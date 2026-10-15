<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Reports\Statements\LedgerActivityReader;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Year-end closing: moves each branch's income and expenditure balance for a
 * fiscal year to the mapped Accumulated Surplus ledger with one posted JV per
 * branch. A closing is undone only by reversing its journals through
 * JournalReversalService; nothing here edits or deletes posted history.
 */
class YearEndClosingService
{
    public const SOURCE_TYPE = LedgerActivityReader::YEAR_END_CLOSING;

    private const VOUCHER_CODE = 'JV';

    public function __construct(
        private LedgerActivityReader $reader,
        private AccountMappingService $mappings,
        private JournalPostingService $journals,
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
    ) {}

    /**
     * Read-only preview of the closing for the branches the actor may see.
     *
     * @return array{branches: list<array{branch: Branch, lines: list<array{account: Account, net: string}>, total_income: string, total_expenses: string, surplus: string}>, total_income: string, total_expenses: string, surplus: string, blockers: list<string>, accumulated_surplus: Account|null, closing_journals: Collection<int, JournalEntry>, closing_date: string, can_post: bool}
     */
    public function preview(FiscalYear $year, User $actor): array
    {
        $branches = $this->branchAccess->scope($actor)->orderBy('name')->get();
        $balances = $this->balances($year, $branches->modelKeys());
        $accounts = Account::query()->whereIn('id', collect($balances)->flatMap(fn (array $lines): array => array_keys($lines))->unique()->all())->get()->keyBy('id');

        $rows = [];
        $income = $this->zero();
        $expenses = $this->zero();
        foreach ($branches as $branch) {
            if (! isset($balances[$branch->id])) {
                continue;
            }
            $summary = $this->summarise($balances[$branch->id], $accounts);
            $income = $income->plus($summary['income']);
            $expenses = $expenses->plus($summary['expenses']);
            $rows[] = [
                'branch' => $branch,
                'lines' => $summary['lines'],
                'total_income' => AccountingDecimal::string($summary['income']),
                'total_expenses' => AccountingDecimal::string($summary['expenses']),
                'surplus' => AccountingDecimal::string($summary['income']->minus($summary['expenses'])),
            ];
        }

        $mapping = AccountMapping::query()->with('account')->where('mapping_key', AccountMappingKey::AccumulatedSurplus->value)->first();

        return [
            'branches' => $rows,
            'total_income' => AccountingDecimal::string($income),
            'total_expenses' => AccountingDecimal::string($expenses),
            'surplus' => AccountingDecimal::string($income->minus($expenses)),
            'blockers' => $this->blockers($year, $this->balances($year)),
            'accumulated_surplus' => $mapping?->account,
            'closing_journals' => JournalEntry::query()->with('branch')
                ->where('source_type', self::SOURCE_TYPE)->where('source_id', (string) $year->id)
                ->whereIn('branch_id', $branches->modelKeys())
                ->orderBy('id')->get(),
            'closing_date' => $year->end_date->toDateString(),
            'can_post' => $this->canPost($actor),
        ];
    }

    /**
     * Post one closing JV per branch with a non-zero income/expenditure balance.
     *
     * @return list<JournalEntry>
     */
    public function post(FiscalYear $year, User $actor, string $reason): array
    {
        abort_unless($this->canPost($actor), 403);

        return DB::transaction(function () use ($year, $actor, $reason): array {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            $balances = $this->balances($year);
            $blockers = $this->blockers($year, $balances);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['closing' => $blockers]);
            }

            $surplusAccount = $this->mappings->resolve(AccountMappingKey::AccumulatedSurplus);
            $period = $this->finalPeriod($year);
            $voucher = VoucherType::query()->where('code', self::VOUCHER_CODE)->where('is_active', true)->first();
            if (! $voucher) {
                throw ValidationException::withMessages(['voucher_type' => 'Voucher type '.self::VOUCHER_CODE.' is not configured or inactive.']);
            }
            $accounts = Account::query()->whereIn('id', collect($balances)->flatMap(fn (array $lines): array => array_keys($lines))->unique()->all())->get()->keyBy('id');

            $posted = [];
            $income = $this->zero();
            $expenses = $this->zero();
            ksort($balances);
            foreach ($balances as $branchId => $lines) {
                $summary = $this->summarise($lines, $accounts);
                $income = $income->plus($summary['income']);
                $expenses = $expenses->plus($summary['expenses']);
                $posted[] = $this->postBranch($year, $period, $voucher, $branchId, $summary['lines'], $surplusAccount, $actor);
            }

            $this->audit->log('fiscal_year.year_end_closing_posted', $year, [
                'fiscal_year' => $year->name,
                'branch_ids' => array_keys($balances),
                'voucher_numbers' => array_map(fn (JournalEntry $journal): string => $journal->voucher_number, $posted),
                'total_income' => AccountingDecimal::string($income),
                'total_expenses' => AccountingDecimal::string($expenses),
                'net_surplus' => AccountingDecimal::string($income->minus($expenses)),
                'accumulated_surplus_account_id' => $surplusAccount->id,
                'reason' => $reason,
            ]);

            return $posted;
        }, 3);
    }

    /**
     * Whether any branch still carries an income or expenditure balance for the year.
     */
    public function hasUnclosedBalance(FiscalYear $year): bool
    {
        return $this->balances($year) !== [];
    }

    /**
     * Non-zero net (debit minus credit) per branch and income/expense ledger for
     * the year, from posted and reversed journals.
     *
     * @param  list<int>|null  $branchIds  Null means every branch.
     * @return array<int, array<int, BigDecimal>>
     */
    public function balances(FiscalYear $year, ?array $branchIds = null): array
    {
        $branchIds ??= Branch::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $balances = [];
        foreach ($this->reader->totals($branchIds, $year->start_date->toDateString(), $year->end_date->toDateString(), [AccountType::Income, AccountType::Expense], LedgerActivityReader::BY_BRANCH) as $row) {
            if (! $row['net']->isZero()) {
                $balances[(int) $row['dimension']][$row['account_id']] = $row['net'];
            }
        }

        return $balances;
    }

    private function canPost(User $actor): bool
    {
        return $actor->can('fiscal_years.close') && $actor->can('journals.post') && $actor->can('branches.view_all');
    }

    /**
     * Organisation-wide reasons the closing cannot be posted now.
     *
     * @param  array<int, array<int, BigDecimal>>  $balances  Balances of every branch.
     * @return list<string>
     */
    private function blockers(FiscalYear $year, array $balances): array
    {
        $blockers = [];
        if ($year->status !== FiscalYearStatus::Open) {
            $blockers[] = "Fiscal year {$year->name} is not open. Only an open year can be closed off.";
        }

        $period = $this->finalPeriod($year);
        if ($period === null) {
            $blockers[] = "No accounting period covers {$year->end_date->format('d M Y')}, the closing date of {$year->name}.";
        } elseif ($period->status !== AccountingPeriodStatus::Open) {
            $blockers[] = "The final accounting period ({$period->name}) is not open. Open it to post the closing entry.";
        }

        $unposted = JournalEntry::query()->where('fiscal_year_id', $year->id)
            ->whereIn('status', [JournalStatus::Draft->value, JournalStatus::Submitted->value, JournalStatus::Approved->value])
            ->count();
        if ($unposted > 0) {
            $blockers[] = "{$unposted} journal(s) of {$year->name} are still draft, submitted or approved but not posted. Post them, return them, or discard the drafts first.";
        }

        $mapping = AccountMapping::query()->with('account')->where('mapping_key', AccountMappingKey::AccumulatedSurplus->value)->first();
        if (! $mapping) {
            $blockers[] = 'The Accumulated Surplus GL mapping is not configured. Map an equity ledger under Accounting Setup → GL Mapping.';
        } elseif (! $this->mappings->isUsable($mapping->account, AccountType::Equity)) {
            $blockers[] = 'The Accumulated Surplus GL mapping points to a ledger that is not an active, postable equity ledger.';
        }

        if ($balances === []) {
            $closed = JournalEntry::query()->where('source_type', self::SOURCE_TYPE)->where('source_id', (string) $year->id)
                ->where('status', JournalStatus::Posted->value)->exists();
            $blockers[] = $closed
                ? "{$year->name} is already closed: the year-end closing entry is posted and no income or expenditure balance remains."
                : "{$year->name} has no posted income or expenditure balance to close.";
        }

        return $blockers;
    }

    /** The accounting period that contains the fiscal year's last day. */
    private function finalPeriod(FiscalYear $year): ?AccountingPeriod
    {
        $endDate = $year->end_date->toDateString();

        return AccountingPeriod::query()->where('fiscal_year_id', $year->id)
            ->whereDate('start_date', '<=', $endDate)->whereDate('end_date', '>=', $endDate)
            ->first();
    }

    /**
     * @param  array<int, BigDecimal>  $balances  Account id => net (debit minus credit).
     * @param  Collection<int, Account>  $accounts
     * @return array{lines: list<array{account: Account, net: string}>, income: BigDecimal, expenses: BigDecimal}
     */
    private function summarise(array $balances, Collection $accounts): array
    {
        $income = $this->zero();
        $expenses = $this->zero();
        $lines = [];
        foreach ($balances as $accountId => $net) {
            $account = $accounts->get($accountId);
            $lines[] = ['account' => $account, 'net' => AccountingDecimal::string($net)];
            if ($account->account_type === AccountType::Income) {
                $income = $income->minus($net);
            } else {
                $expenses = $expenses->plus($net);
            }
        }
        usort($lines, fn (array $left, array $right): int => [$left['account']->account_type === AccountType::Income ? 0 : 1, $left['account']->code] <=> [$right['account']->account_type === AccountType::Income ? 0 : 1, $right['account']->code]);

        return ['lines' => $lines, 'income' => $income, 'expenses' => $expenses];
    }

    /** @param list<array{account: Account, net: string}> $balanceLines */
    private function postBranch(FiscalYear $year, AccountingPeriod $period, VoucherType $voucher, int $branchId, array $balanceLines, Account $surplusAccount, User $actor): JournalEntry
    {
        $zero = AccountingDecimal::string($this->zero());
        $lines = [];
        $total = $this->zero();
        $net = $this->zero();
        foreach ($balanceLines as $balance) {
            $amount = AccountingDecimal::of($balance['net']);
            $net = $net->plus($amount);
            $closing = AccountingDecimal::string($amount->abs());
            $lines[] = [
                'account_id' => $balance['account']->id,
                'description' => "Close {$balance['account']->code} {$balance['account']->name} to accumulated surplus",
                'debit' => $amount->isNegative() ? $closing : $zero,
                'credit' => $amount->isPositive() ? $closing : $zero,
            ];
            $total = $total->plus($amount->isNegative() ? $amount->abs() : $this->zero());
        }
        if (! $net->isZero()) {
            $lines[] = [
                'account_id' => $surplusAccount->id,
                'description' => $net->isNegative() ? "Surplus for {$year->name}" : "Deficit for {$year->name}",
                'debit' => $net->isPositive() ? AccountingDecimal::string($net) : $zero,
                'credit' => $net->isNegative() ? AccountingDecimal::string($net->abs()) : $zero,
            ];
            $total = $total->plus($net->isPositive() ? $net : $this->zero());
        }

        $baseKey = self::SOURCE_TYPE.":{$year->id}:branch:{$branchId}";
        $previous = JournalEntry::query()->where('source_type', self::SOURCE_TYPE)->where('source_id', (string) $year->id)->where('branch_id', $branchId)->count();
        $journal = JournalEntry::query()->create([
            'voucher_type_id' => $voucher->id, 'transaction_date' => $year->end_date->toDateString(),
            'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id,
            'branch_id' => $branchId, 'narration' => "Year-end closing {$year->name}", 'reference_number' => null,
            'source_system' => 'accounting', 'source_type' => self::SOURCE_TYPE, 'source_id' => (string) $year->id,
            'source_key' => $previous === 0 ? $baseKey : $baseKey.':run:'.($previous + 1),
            'status' => JournalStatus::Approved, 'total_debit' => AccountingDecimal::string($total), 'total_credit' => AccountingDecimal::string($total),
            'created_by' => $actor->id, 'submitted_by' => $actor->id, 'approved_by' => $actor->id,
            'submitted_at' => now(), 'approved_at' => now(),
        ]);
        foreach ($lines as $index => $line) {
            $journal->lines()->create(['line_number' => $index + 1, 'service_id' => null, ...$line]);
        }

        return $this->journals->postSystem($journal, $actor);
    }

    private function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(4);
    }
}
