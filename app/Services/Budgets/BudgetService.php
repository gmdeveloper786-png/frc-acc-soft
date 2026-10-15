<?php

namespace App\Services\Budgets;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\BudgetStatus;
use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Budgets are planning records. Nothing in this service posts to the General
 * Ledger and nothing here blocks a transaction: overruns are reported, never
 * enforced.
 */
class BudgetService
{
    /** Rows per bulk insert: six bindings a row stays far below every driver's placeholder limit. */
    private const LINE_CHUNK = 150;

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
    ) {}

    /** @param array{fiscal_year_id: int|string, branch_id: int|string, service_id?: int|string|null, name: string, lines: list<array{account_id: int|string, accounting_period_id: int|string, amount: mixed}>} $data */
    public function create(array $data, User $actor): Budget
    {
        $this->authorize($actor, 'budgets.create');

        return DB::transaction(function () use ($data, $actor): Budget {
            $scope = $this->guardHeader($data, $actor);
            $computed = $this->computeLines($data['lines'] ?? [], $scope['year']);

            $budget = Budget::query()->create([
                'fiscal_year_id' => $scope['year']->id, 'branch_id' => $scope['branch']->id, 'service_id' => $scope['service']?->id,
                'name' => $data['name'], 'version' => 1, 'status' => BudgetStatus::Draft,
                'total_amount' => $computed['total'], 'created_by' => $actor->id,
            ]);
            $this->insertLines($budget, $computed['lines']);
            $this->record($budget, 'created', null, $actor);
            $this->audit->log('budgets.created', $budget, $this->auditContext($budget));

            return $budget->load('lines');
        }, 3);
    }

    /** @param array{fiscal_year_id: int|string, branch_id: int|string, service_id?: int|string|null, name: string, lines: list<array{account_id: int|string, accounting_period_id: int|string, amount: mixed}>} $data */
    public function update(Budget $budget, array $data, User $actor): Budget
    {
        $this->authorize($actor, 'budgets.update');

        return DB::transaction(function () use ($budget, $data, $actor): Budget {
            $budget = $this->lock($budget, $actor);
            if (! $budget->status->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft or returned budget can be edited. Revise an approved budget instead.']);
            }
            // A revision keeps the fiscal year, branch and service of the budget it revises.
            if ($budget->root_budget_id !== null) {
                $data = [...$data, 'fiscal_year_id' => $budget->fiscal_year_id, 'branch_id' => $budget->branch_id, 'service_id' => $budget->service_id];
            }
            $scope = $this->guardHeader($data, $actor);
            $computed = $this->computeLines($data['lines'] ?? [], $scope['year']);
            $from = $budget->status;
            $previousTotal = $budget->total_amount;

            $budget->update([
                'fiscal_year_id' => $scope['year']->id, 'branch_id' => $scope['branch']->id, 'service_id' => $scope['service']?->id,
                'name' => $data['name'], 'total_amount' => $computed['total'], 'status' => BudgetStatus::Draft, 'rejection_reason' => null,
            ]);
            $this->syncLines($budget, $computed['lines']);
            $this->record($budget, 'updated', $from, $actor, "Total changed from {$previousTotal} to {$budget->total_amount}.");
            $this->audit->log('budgets.updated', $budget, [...$this->auditContext($budget), 'previous_total' => $previousTotal], $actor);

            return $budget->load('lines');
        });
    }

    public function submit(Budget $budget, User $actor): Budget
    {
        $this->authorize($actor, 'budgets.submit');

        return DB::transaction(function () use ($budget, $actor): Budget {
            $budget = $this->lock($budget, $actor);
            if (! $budget->status->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft or returned budget can be submitted.']);
            }
            $this->assertIntegrity($budget);
            $this->assertNoCompetingApproved($budget);
            $from = $budget->status;
            $budget->update(['status' => BudgetStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->record($budget, 'submitted', $from, $actor);
            $this->audit->log('budgets.submitted', $budget, $this->auditContext($budget));

            return $budget;
        });
    }

    /**
     * Approving a revision supersedes the previously approved version of the
     * same chain in the same transaction, so one approved budget exists per
     * fiscal year, branch and service scope at all times.
     */
    public function approve(Budget $budget, User $actor, ?string $comments = null): Budget
    {
        $this->authorize($actor, 'budgets.approve');

        return DB::transaction(function () use ($budget, $actor, $comments): Budget {
            $budget = $this->lock($budget, $actor);
            if ($budget->status !== BudgetStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted budget can be approved.']);
            }
            $this->assertNotMaker($budget, $actor);
            $this->assertIntegrity($budget);
            $this->assertNoCompetingApproved($budget);

            $previous = $budget->versions()->where('status', BudgetStatus::Approved->value)->whereKeyNot($budget->id)->lockForUpdate()->first();
            if ($previous) {
                $previous->update(['status' => BudgetStatus::Superseded]);
                $this->record($previous, 'superseded', BudgetStatus::Approved, $actor, "Superseded by version {$budget->version}.");
                $this->audit->log('budgets.superseded', $previous, [...$this->auditContext($previous), 'superseded_by_budget_id' => $budget->id, 'superseded_by_version' => $budget->version]);
            }

            $budget->update(['status' => BudgetStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->record($budget, 'approved', BudgetStatus::Submitted, $actor, $comments);
            $this->audit->log('budgets.approved', $budget, [...$this->auditContext($budget), 'superseded_budget_id' => $previous?->id]);

            return $budget;
        });
    }

    public function reject(Budget $budget, string $reason, User $actor): Budget
    {
        $this->authorize($actor, 'budgets.approve');

        return DB::transaction(function () use ($budget, $reason, $actor): Budget {
            $budget = $this->lock($budget, $actor);
            if ($budget->status !== BudgetStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted budget can be rejected.']);
            }
            $this->assertNotMaker($budget, $actor);
            $budget->update(['status' => BudgetStatus::Rejected, 'rejection_reason' => $reason]);
            $this->record($budget, 'rejected', BudgetStatus::Submitted, $actor, $reason);
            $this->audit->log('budgets.rejected', $budget, [...$this->auditContext($budget), 'reason' => $reason]);

            return $budget;
        });
    }

    /** Start the next draft version of an approved budget. The approved version stays in force until the revision is approved. */
    public function revise(Budget $budget, string $reason, User $actor): Budget
    {
        $this->authorize($actor, 'budgets.revise');
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to revise an approved budget.']);
        }

        return DB::transaction(function () use ($budget, $reason, $actor): Budget {
            $budget = $this->lock($budget, $actor);
            if ($budget->status !== BudgetStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Only the approved version of a budget can be revised.']);
            }
            $versions = $budget->versions()->lockForUpdate()->get();
            if ($versions->contains(fn (Budget $version): bool => $version->status->isOpen())) {
                throw ValidationException::withMessages(['status' => 'A revision of this budget is already in progress. Finish or use that version.']);
            }

            $revision = Budget::query()->create([
                'fiscal_year_id' => $budget->fiscal_year_id, 'branch_id' => $budget->branch_id, 'service_id' => $budget->service_id,
                'name' => $budget->name, 'version' => (int) $versions->max('version') + 1, 'root_budget_id' => $budget->rootId(),
                'status' => BudgetStatus::Draft, 'total_amount' => $budget->total_amount, 'created_by' => $actor->id, 'revision_reason' => $reason,
            ]);
            $this->insertLines($revision, DB::table('budget_lines')->where('budget_id', $budget->id)->orderBy('id')->get(['account_id', 'accounting_period_id', 'amount'])
                ->map(fn (object $line): array => ['account_id' => (int) $line->account_id, 'accounting_period_id' => (int) $line->accounting_period_id, 'amount' => AccountingDecimal::string(AccountingDecimal::of($line->amount))])->all());
            $this->record($budget, 'revised', BudgetStatus::Approved, $actor, "Revision started as version {$revision->version}: {$reason}");
            $this->record($revision, 'created', null, $actor, "Revision of version {$budget->version}: {$reason}");
            $this->audit->log('budgets.revised', $revision, [...$this->auditContext($revision), 'revised_budget_id' => $budget->id, 'revised_version' => $budget->version, 'reason' => $reason]);

            return $revision->load('lines');
        }, 3);
    }

    /** Whether the user may approve or reject this submitted budget right now. */
    public function canApprove(Budget $budget, User $actor): bool
    {
        return $budget->status === BudgetStatus::Submitted
            && $actor->is_active && $actor->can('budgets.approve')
            && ! in_array($actor->id, [$budget->created_by, $budget->submitted_by, ...$this->editors($budget)], true)
            && $this->branchAccess->canAccess($actor, $budget->branch);
    }

    /**
     * Spread an annual amount equally over the periods of a fiscal year. Each
     * period gets the amount divided by the number of periods, truncated to
     * four decimals; the last period absorbs the remainder so the total is exact.
     *
     * @return array<int, string> amount keyed by accounting period id, in period order
     */
    public function spreadAnnual(FiscalYear $year, mixed $annualAmount): array
    {
        $annual = $this->amount($annualAmount, 'annual_amount');
        $periodIds = $year->periods()->pluck('id')->all();
        if ($periodIds === []) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'The fiscal year has no accounting periods to spread over.']);
        }

        $count = count($periodIds);
        $share = $annual->dividedBy($count, 4, RoundingMode::Down);
        $spread = [];
        foreach ($periodIds as $index => $periodId) {
            $isLast = $index === $count - 1;
            $spread[(int) $periodId] = AccountingDecimal::string($isLast ? $annual->minus($share->multipliedBy($count - 1)) : $share);
        }

        return $spread;
    }

    /** Ledgers that may carry a budget: active, postable income or expense ledgers. */
    public function budgetableAccounts(): Builder
    {
        return Account::query()
            ->whereIn('account_type', [AccountType::Expense->value, AccountType::Income->value])
            ->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array{account_id: int, accounting_period_id: int, amount: string}>, total: string}
     */
    private function computeLines(array $lines, FiscalYear $year): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A budget requires at least one line.']);
        }
        $lines = array_values($lines);
        // Looked up once and held as hash maps: a full Chart of Accounts budget has thousands of lines.
        $periodIds = array_fill_keys($year->periods()->pluck('id')->map(fn ($id): int => (int) $id)->all(), true);
        $requested = array_values(array_unique(array_map(fn (mixed $line): int => is_array($line) && is_scalar($line['account_id'] ?? null) ? (int) $line['account_id'] : 0, $lines)));
        $accountIds = [];
        foreach (array_chunk($requested, 500) as $chunk) {
            $accountIds += array_fill_keys($this->budgetableAccounts()->whereKey($chunk)->pluck('id')->map(fn ($id): int => (int) $id)->all(), true);
        }
        $total = BigDecimal::zero()->toScale(4);
        $rows = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $line = is_array($line) ? $line : [];
            $accountId = is_scalar($line['account_id'] ?? null) ? (int) $line['account_id'] : 0;
            $periodId = is_scalar($line['accounting_period_id'] ?? null) ? (int) $line['accounting_period_id'] : 0;
            if (! isset($accountIds[$accountId])) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Only an active, postable income or expense ledger can be budgeted. Group accounts cannot carry a budget.']);
            }
            if (! isset($periodIds[$periodId])) {
                throw ValidationException::withMessages(["lines.{$index}.accounting_period_id" => 'The period does not belong to the fiscal year of this budget.']);
            }
            if (isset($seen[$accountId.':'.$periodId])) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each account can be budgeted only once per period.']);
            }
            $seen[$accountId.':'.$periodId] = true;
            $amount = $this->amount($line['amount'] ?? null, "lines.{$index}.amount");
            $rows[] = ['account_id' => $accountId, 'accounting_period_id' => $periodId, 'amount' => AccountingDecimal::string($amount)];
            $total = $total->plus($amount);
        }

        return ['lines' => $rows, 'total' => AccountingDecimal::string($total)];
    }

    /**
     * Bulk insert in chunks. Model events are skipped on purpose: the caller
     * holds the budget row lock and has already checked that it is editable.
     *
     * @param  list<array{account_id: int, accounting_period_id: int, amount: string}>  $lines
     */
    private function insertLines(Budget $budget, array $lines): void
    {
        $now = now();
        foreach (array_chunk($lines, self::LINE_CHUNK) as $chunk) {
            BudgetLine::query()->insert(array_map(fn (array $line): array => [...$line, 'budget_id' => $budget->id, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    /**
     * Make the stored lines equal the given complete set. Lines whose account,
     * period and amount are unchanged are left untouched; only changed, removed
     * and new lines are written, in chunks.
     *
     * @param  list<array{account_id: int, accounting_period_id: int, amount: string}>  $lines
     */
    private function syncLines(Budget $budget, array $lines): void
    {
        $existing = [];
        foreach (DB::table('budget_lines')->where('budget_id', $budget->id)->get(['id', 'account_id', 'accounting_period_id', 'amount']) as $row) {
            $existing[$row->account_id.':'.$row->accounting_period_id] = $row;
        }
        $insert = [];
        $delete = [];
        foreach ($lines as $line) {
            $key = $line['account_id'].':'.$line['accounting_period_id'];
            $current = $existing[$key] ?? null;
            unset($existing[$key]);
            if ($current && AccountingDecimal::of($current->amount)->isEqualTo(AccountingDecimal::of($line['amount']))) {
                continue;
            }
            if ($current) {
                $delete[] = (int) $current->id;
            }
            $insert[] = $line;
        }
        foreach ($existing as $removed) {
            $delete[] = (int) $removed->id;
        }
        foreach (array_chunk($delete, 500) as $chunk) {
            DB::table('budget_lines')->where('budget_id', $budget->id)->whereIn('id', $chunk)->delete();
        }
        $this->insertLines($budget, $insert);
    }

    /** A non-negative amount with at most four decimals. */
    private function amount(mixed $value, string $field): BigDecimal
    {
        try {
            $amount = AccountingDecimal::of(is_string($value) ? trim($value) : $value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter a valid amount with at most four decimals.']);
        }
        if ($value === null || $value === '' || $amount->isNegative()) {
            throw ValidationException::withMessages([$field => 'A budget amount cannot be negative or empty.']);
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{year: FiscalYear, branch: Branch, service: ?FrcService}
     */
    private function guardHeader(array $data, User $actor): array
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        $year = FiscalYear::query()->findOrFail($data['fiscal_year_id']);
        if ($year->status === FiscalYearStatus::Closed) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'A closed fiscal year cannot be budgeted.']);
        }
        $service = null;
        if (filled($data['service_id'] ?? null)) {
            $service = FrcService::query()->whereKey($data['service_id'])->whereIn('status', ['publish', 'active'])->first();
            if (! $service) {
                throw ValidationException::withMessages(['service_id' => 'The selected service is not available.']);
            }
        }
        if (blank($data['name'] ?? null)) {
            throw ValidationException::withMessages(['name' => 'A budget name is required.']);
        }

        return ['year' => $year, 'branch' => $branch, 'service' => $service];
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->is_active && $actor->can($permission), 403);
    }

    private function lock(Budget $budget, User $actor): Budget
    {
        $budget = Budget::query()->with('branch')->lockForUpdate()->findOrFail($budget->id);
        abort_unless($this->branchAccess->canAccess($actor, $budget->branch), 403);

        return $budget;
    }

    private function assertNotMaker(Budget $budget, User $actor): void
    {
        if (in_array($actor->id, [$budget->created_by, $budget->submitted_by], true)) {
            throw ValidationException::withMessages(['approval' => 'The user who created or submitted a budget cannot approve or reject it.']);
        }
        if (in_array($actor->id, $this->editors($budget), true)) {
            throw ValidationException::withMessages(['approval' => 'You edited this budget, so another user must approve it.']);
        }
    }

    /** @return list<int> Users who changed the budget lines while it was a draft. */
    private function editors(Budget $budget): array
    {
        return $this->audit->actorIds($budget, ['budgets.updated']);
    }

    /** The header total must still equal the stored lines and every line must still be budgetable. */
    private function assertIntegrity(Budget $budget): void
    {
        $lines = $budget->lines()->get();
        $sum = $lines->reduce(fn (BigDecimal $carry, BudgetLine $line): BigDecimal => $carry->plus(AccountingDecimal::of($line->amount)), BigDecimal::zero()->toScale(4));
        if ($lines->isEmpty() || ! $sum->isEqualTo(AccountingDecimal::of($budget->total_amount))) {
            throw ValidationException::withMessages(['status' => 'Budget total does not match its lines. Edit and save the budget again.']);
        }
        $accountIds = $lines->pluck('account_id')->unique();
        if ($this->budgetableAccounts()->whereKey($accountIds->all())->count() !== $accountIds->count()) {
            throw ValidationException::withMessages(['status' => 'This budget contains an account that is no longer an active postable income or expense ledger.']);
        }
        if (AccountingPeriod::query()->whereKey($lines->pluck('accounting_period_id')->unique()->all())->where('fiscal_year_id', '!=', $budget->fiscal_year_id)->exists()) {
            throw ValidationException::withMessages(['status' => 'This budget contains a period outside its fiscal year.']);
        }
    }

    /** Another budget chain already approved for the same fiscal year, branch and service blocks this one. */
    private function assertNoCompetingApproved(Budget $budget): void
    {
        $competing = Budget::query()
            ->where('fiscal_year_id', $budget->fiscal_year_id)->where('branch_id', $budget->branch_id)->where('service_scope', $budget->service_scope)
            ->where('status', BudgetStatus::Approved->value)->whereKeyNot($budget->id)->lockForUpdate()->get()
            ->first(fn (Budget $other): bool => $other->rootId() !== $budget->rootId());
        if ($competing) {
            throw ValidationException::withMessages(['status' => "An approved budget (\"{$competing->name}\" v{$competing->version}) already exists for this fiscal year, branch and service. Revise that budget instead."]);
        }
    }

    /** @return array<string, mixed> */
    private function auditContext(Budget $budget): array
    {
        return [
            'name' => $budget->name, 'version' => $budget->version, 'fiscal_year_id' => $budget->fiscal_year_id, 'branch_id' => $budget->branch_id,
            'service_id' => $budget->service_id, 'status' => $budget->status->value, 'total_amount' => $budget->total_amount,
        ];
    }

    private function record(Budget $budget, string $action, ?BudgetStatus $from, User $actor, ?string $comments = null): void
    {
        $budget->approvals()->create([
            'action' => $action, 'from_status' => $from?->value, 'to_status' => $budget->status->value,
            'total_amount' => $budget->total_amount, 'actor_id' => $actor->id, 'comments' => $comments, 'created_at' => now(),
        ]);
    }
}
