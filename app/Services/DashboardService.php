<?php

namespace App\Services;

use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\BudgetStatus;
use App\Enums\DepreciationRunStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\InventoryMovementStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollRunStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Budget;
use App\Models\DepreciationRun;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollRun;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Budgets\BudgetReportService;
use App\Services\Reports\Statements\LedgerActivityReader;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Read-only dashboard figures. Every amount comes from posted journals or
 * module records of the viewer's branches. A figure that cannot be computed is
 * returned as null (unavailable), never as zero, and a figure the viewer may
 * not see is not computed at all.
 */
class DashboardService
{
    private const LEDGER_STATUSES = [JournalStatus::Posted, JournalStatus::Reversed];

    public function __construct(
        private BranchAccess $branchAccess,
        private AccountingReadinessService $readiness,
        private BudgetReportService $budgetReports,
    ) {}

    /**
     * @return array{chart_is_empty: bool, branches: Collection<int, string>, all_branches: bool, fiscal_year: ?FiscalYear, figures: list<array{key: string, label: string, value: ?string, percent: bool, tone: string, icon: string, status: string, description: string}>, pending: ?array{total: int, items: list<array{label: string, count: int, route: ?string}>}, recent_journals: ?Collection<int, JournalEntry>, setup: array{ready: bool, detailed: bool, checks: list<array<string, mixed>>}}
     */
    public function overview(User $user): array
    {
        abort_unless($user->is_active && $user->can('dashboard.view'), 403);

        $allBranches = $user->can('branches.view_all');
        $branchNames = $this->branchAccess->scope($user)->orderBy('name')->pluck('name', 'id');
        $branchIds = $allBranches ? null : $branchNames->keys()->map(fn ($id): int => (int) $id)->all();
        $chartIsEmpty = ! Account::query()->exists();
        $openYears = FiscalYear::query()->where('status', FiscalYearStatus::Open->value)->orderByDesc('start_date')->get();
        $year = $openYears->firstWhere('is_current', true)
            ?? $openYears->first(fn (FiscalYear $candidate): bool => $candidate->start_date->lte(today()) && $candidate->end_date->gte(today()));

        $figures = [];
        if ($user->canAny(['ledgers.view', 'financial_statements.view'])) {
            $figures = [...$figures, ...$this->cashAndBank($branchIds, $chartIsEmpty), ...$this->incomeAndExpense($branchIds, $chartIsEmpty, $year)];
        }
        if ($user->can('finance_reports.view')) {
            $figures[] = $this->payables($branchIds, $chartIsEmpty);
        }
        if ($user->can('budget_reports.view')) {
            $figures[] = $this->budgetUtilisation($user, $branchIds, $chartIsEmpty, $year);
        }

        return [
            'chart_is_empty' => $chartIsEmpty, 'branches' => $branchNames->values(), 'all_branches' => $allBranches, 'fiscal_year' => $year,
            'figures' => $figures, 'pending' => $this->pendingApprovals($user, $branchIds),
            'recent_journals' => $user->can('journals.view') ? $this->recentJournals($branchIds) : null,
            'setup' => $this->setup($user),
        ];
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function cashAndBank(?array $branchIds, bool $chartIsEmpty): array
    {
        $types = FinancialAccount::query()->pluck('type', 'account_id');
        $pettyCash = AccountMapping::query()->where('mapping_key', AccountMappingKey::PettyCash->value)->pluck('account_id');
        $ledgers = ['cash' => [], 'bank' => []];
        foreach ($types as $accountId => $type) {
            $ledgers[$type === 'bank' ? 'bank' : 'cash'][(int) $accountId] = true;
        }
        foreach ($pettyCash as $accountId) {
            if (! isset($ledgers['bank'][(int) $accountId])) {
                $ledgers['cash'][(int) $accountId] = true;
            }
        }

        $balances = [];
        $allIds = [...array_keys($ledgers['cash']), ...array_keys($ledgers['bank'])];
        if (! $chartIsEmpty && $allIds !== []) {
            $sums = $this->ledgerLines($branchIds)->whereIn('journal_lines.account_id', $allIds)->groupBy('journal_lines.account_id')
                ->selectRaw('journal_lines.account_id as account_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')->get();
            foreach ($sums as $sum) {
                $balances[(int) $sum->account_id] = $this->sum($sum->debit)->minus($this->sum($sum->credit));
            }
        }

        $figures = [];
        foreach (['cash' => ['Cash Balance', 'teal', 'C', 'cash or petty cash'], 'bank' => ['Bank Balance', 'navy', 'B', 'bank']] as $kind => [$label, $tone, $icon, $noun]) {
            if ($chartIsEmpty) {
                $figures[] = $this->unavailable($kind.'_balance', $label, $tone, $icon, 'Accounting setup required', 'The Chart of Accounts is empty.');

                continue;
            }
            if ($ledgers[$kind] === []) {
                $figures[] = $this->unavailable($kind.'_balance', $label, $tone, $icon, 'Not available', "No {$noun} account is configured.");

                continue;
            }
            $total = BigDecimal::zero()->toScale(4);
            foreach (array_keys($ledgers[$kind]) as $accountId) {
                $total = $total->plus($balances[$accountId] ?? '0');
            }
            $figures[] = $this->figure($kind.'_balance', $label, AccountingDecimal::string($total), $tone, $icon, 'Posted ledger', 'Posted balance of '.count($ledgers[$kind]).' '.$noun.' '.str('ledger')->plural(count($ledgers[$kind])).'.');
        }

        return $figures;
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function incomeAndExpense(?array $branchIds, bool $chartIsEmpty, ?FiscalYear $year): array
    {
        $cards = ['income' => ['Income', 'blue', 'I'], 'expense' => ['Expenses', 'amber', 'E'], 'surplus' => ['Net Surplus', 'green', 'S']];
        if ($chartIsEmpty || $year === null) {
            return array_values(array_map(fn (string $key, array $card): array => $this->unavailable($key, $card[0], $card[1], $card[2],
                $chartIsEmpty ? 'Accounting setup required' : 'Not available',
                $chartIsEmpty ? 'The Chart of Accounts is empty.' : 'No fiscal year is open.'), array_keys($cards), $cards));
        }

        $sums = $this->ledgerLines($branchIds)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.account_type', [AccountType::Income->value, AccountType::Expense->value])
            ->whereDate('journal_entries.transaction_date', '>=', $year->start_date->toDateString())
            ->whereDate('journal_entries.transaction_date', '<=', $year->end_date->toDateString())
            ->tap(fn (Builder $query) => LedgerActivityReader::withoutYearEndClosing($query))
            ->groupBy('accounts.account_type')
            ->selectRaw('accounts.account_type as account_type, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit, COUNT(*) as line_count')
            ->get()->keyBy('account_type');
        $income = $this->sum($sums->get('income')?->credit)->minus($this->sum($sums->get('income')?->debit));
        $expense = $this->sum($sums->get('expense')?->debit)->minus($this->sum($sums->get('expense')?->credit));
        $note = $sums->isEmpty() ? 'No posted income or expense journals in '.$year->name.' yet.' : 'Posted journals of '.$year->name.'; reversals net to zero.';

        return [
            $this->figure('income', 'Income', AccountingDecimal::string($income), 'blue', 'I', $year->name, $note),
            $this->figure('expense', 'Expenses', AccountingDecimal::string($expense), 'amber', 'E', $year->name, $note),
            $this->figure('surplus', 'Net Surplus', AccountingDecimal::string($income->minus($expense)), 'green', 'S', $year->name, 'Income less expenses on the accrual basis.'),
        ];
    }

    /**
     * Posted purchase bills less the posted payments made against them, the
     * same rule as PurchaseBill::outstandingAmount(), in two grouped queries.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function payables(?array $branchIds, bool $chartIsEmpty): array
    {
        if ($chartIsEmpty) {
            return $this->unavailable('payables', 'Payables Outstanding', 'rose', 'P', 'Accounting setup required', 'The Chart of Accounts is empty.');
        }
        $bills = PurchaseBill::query()->where('status', PurchaseBillStatus::Posted->value)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds));
        $billed = (clone $bills)->selectRaw('COUNT(*) as bill_count, SUM(total_amount) as total')->toBase()->first();
        $paid = OperationalTransaction::query()->where('status', OperationalTransactionStatus::Posted->value)
            ->whereIn('purchase_bill_id', (clone $bills)->select('id'))->sum('amount');
        $count = (int) $billed->bill_count;

        return $this->figure('payables', 'Payables Outstanding', AccountingDecimal::string($this->sum($billed->total)->minus($this->sum($paid))), 'rose', 'P',
            $count.' posted '.str('bill')->plural($count), $count === 0 ? 'No posted purchase bills.' : 'Posted purchase bills less posted vendor payments.');
    }

    /**
     * Expense utilisation of the approved whole-branch budgets of the open
     * fiscal year, from the Budget vs Actual report (no alerts are recorded).
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function budgetUtilisation(User $user, ?array $branchIds, bool $chartIsEmpty, ?FiscalYear $year): array
    {
        $unavailable = fn (string $status, string $description): array => $this->unavailable('budget', 'Budget Utilisation', 'purple', '%', $status, $description);
        if ($chartIsEmpty) {
            return $unavailable('Accounting setup required', 'The Chart of Accounts is empty.');
        }
        if ($year === null) {
            return $unavailable('Not available', 'No fiscal year is open.');
        }
        $budgets = Budget::query()->with(['fiscalYear', 'branch', 'service'])->where('fiscal_year_id', $year->id)->whereNull('service_id')
            ->where('status', BudgetStatus::Approved->value)->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))->get();
        if ($budgets->isEmpty()) {
            return $unavailable('Not available', 'No approved budget for '.$year->name.'.');
        }

        [$budgeted, $actual] = [BigDecimal::zero()->toScale(4), BigDecimal::zero()->toScale(4)];
        foreach ($budgets as $budget) {
            if ($budget->fiscalYear->periods()->doesntExist()) {
                continue;
            }
            $totals = $this->budgetReports->versusActual($budget, $user, null, null, false)['totals']['expense'];
            $budgeted = $budgeted->plus($totals['budget']);
            $actual = $actual->plus($totals['actual']);
        }
        if (! $budgeted->isPositive()) {
            return $unavailable('Not available', 'The approved budget has no expense allocation.');
        }

        return [...$this->figure('budget', 'Budget Utilisation', (string) $actual->multipliedBy(100)->dividedBy($budgeted, 2, RoundingMode::HalfUp), 'purple', '%',
            $budgets->count().' approved '.str('budget')->plural($budgets->count()), 'Posted expenses against the approved expense budget of '.$year->name.'.'), 'percent' => true];
    }

    /**
     * Counts of records waiting for approval, only for modules the user may view.
     *
     * @param  list<int>|null  $branchIds
     * @return array{total: int, items: list<array{label: string, count: int, route: ?string}>}|null Null when no module is visible.
     */
    private function pendingApprovals(User $user, ?array $branchIds): ?array
    {
        $scoped = fn ($query) => $query->when($branchIds !== null, fn ($inner) => $inner->whereIn('branch_id', $branchIds));
        $items = [];
        $add = function (string $label, int $count, string $route) use (&$items): void {
            $items[] = ['label' => $label, 'count' => $count, 'route' => Route::has($route) ? $route : null];
        };

        if ($user->can('journals.view')) {
            $add('Journals', $scoped(JournalEntry::query())->where('status', JournalStatus::Submitted->value)->count(), 'journals.index');
        }
        $visibleTypes = array_values(array_filter(OperationalTransactionType::cases(), fn (OperationalTransactionType $type): bool => $type->requiresApproval() && $user->can($type->permissionPrefix().'.view')));
        if ($visibleTypes !== []) {
            $add('Payments and expenses', $scoped(OperationalTransaction::query())->where('status', OperationalTransactionStatus::Submitted->value)
                ->whereIn('type', array_map(fn (OperationalTransactionType $type): string => $type->value, $visibleTypes))->count(), '-');
        }
        if ($user->can('purchase_orders.view')) {
            $add('Purchase orders', $scoped(PurchaseOrder::query())->where('status', PurchaseOrderStatus::Submitted->value)->count(), 'purchase-orders.index');
        }
        if ($user->can('purchase_bills.view')) {
            $add('Purchase bills', $scoped(PurchaseBill::query())->where('status', PurchaseBillStatus::Submitted->value)->count(), 'purchase-bills.index');
        }
        if ($user->can('depreciation.view')) {
            $add('Depreciation runs', $scoped(DepreciationRun::query())->where('status', DepreciationRunStatus::Submitted->value)->count(), 'depreciation-runs.index');
        }
        if ($user->can('budgets.view')) {
            $add('Budgets', $scoped(Budget::query())->where('status', BudgetStatus::Submitted->value)->count(), 'budgets.index');
        }
        if ($user->can('payroll.view')) {
            $add('Payroll runs', $scoped(PayrollRun::query())->where('status', PayrollRunStatus::Submitted->value)->count(), 'payroll-runs.index');
        }
        if ($user->can('inventory.view')) {
            $add('Inventory adjustments', $scoped(InventoryMovement::query())->where('status', InventoryMovementStatus::Pending->value)->count(), 'inventory.adjustments.index');
        }

        return $items === [] ? null : ['total' => array_sum(array_column($items, 'count')), 'items' => $items];
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return Collection<int, JournalEntry>
     */
    private function recentJournals(?array $branchIds): Collection
    {
        return JournalEntry::query()->with(['voucherType:id,code,name', 'branch:id,name'])
            ->whereIn('status', array_map(fn (JournalStatus $status): string => $status->value, self::LEDGER_STATUSES))
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->orderByDesc('posted_at')->orderByDesc('id')->limit(8)->get();
    }

    /**
     * Outstanding setup requirements. The details are only for users who may
     * open the readiness page; everyone else learns only that setup is incomplete.
     *
     * @return array{ready: bool, detailed: bool, checks: list<array<string, mixed>>}
     */
    private function setup(User $user): array
    {
        $failing = array_values(array_filter($this->readiness->checks(), fn (array $check): bool => ! $check['ready']));
        $detailed = $user->can('accounting_setup.readiness');

        return [
            'ready' => $failing === [], 'detailed' => $detailed,
            'checks' => $detailed ? array_map(fn (array $check): array => [...$check, 'route' => Route::has($check['route']) ? $check['route'] : null], $failing) : [],
        ];
    }

    /**
     * Lines of journals that count as ledger activity in the viewer's branches.
     *
     * @param  list<int>|null  $branchIds
     */
    private function ledgerLines(?array $branchIds): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', array_map(fn (JournalStatus $status): string => $status->value, self::LEDGER_STATUSES))
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('journal_entries.branch_id', $branchIds));
    }

    /** @return array<string, mixed> */
    private function figure(string $key, string $label, string $value, string $tone, string $icon, string $status, string $description): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'percent' => false, 'tone' => $tone, 'icon' => $icon, 'status' => $status, 'description' => $description];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $key, string $label, string $tone, string $icon, string $status, string $description): array
    {
        return ['key' => $key, 'label' => $label, 'value' => null, 'percent' => false, 'tone' => $tone, 'icon' => $icon, 'status' => $status, 'description' => $description];
    }

    /** A database SUM of DECIMAL(19,4) columns as an exact four-decimal value. */
    private function sum(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }
}
