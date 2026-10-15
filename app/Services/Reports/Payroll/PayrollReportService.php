<?php

namespace App\Services\Reports\Payroll;

use App\Enums\OperationalTransactionStatus;
use App\Enums\PayrollComponentType;
use App\Enums\PayrollRunStatus;
use App\Enums\StaffAdvanceStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Models\PayrollImport;
use App\Models\PayrollImportRow;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only payroll reports. Every figure is a stored amount of a payroll run,
 * its posted journal or its linked payments: nothing is calculated from a tax,
 * statutory or leave rule, and nothing is ever posted or changed here.
 *
 * Privacy is decided in this class, not in the views. A user without
 * payroll.view_sensitive receives tables that contain no amount column at all,
 * and reports that consist only of amounts are refused. The screen and the
 * export are rendered from the same table.
 */
class PayrollReportService
{
    /** Report key => title. */
    public const REPORTS = [
        'register' => 'Payroll Register',
        'employee-history' => 'Employee Payroll History',
        'run-summary' => 'Payroll Run Summary',
        'accrual-summary' => 'Salary Accrual Summary',
        'payment-status' => 'Salary Payment Status',
        'deductions' => 'Deductions Summary',
        'import-errors' => 'Import Validation Errors',
        'advances-outstanding' => 'Staff Advances Outstanding',
    ];

    /** Reports whose whole content is salary amounts: refused without payroll.view_sensitive. */
    public const AMOUNT_ONLY = ['register', 'deductions', 'advances-outstanding'];

    private const ACCRUED_STATUSES = [PayrollRunStatus::Posted, PayrollRunStatus::Paid];

    private const HIDDEN = '[hidden]';

    public function __construct(private BranchAccess $branchAccess) {}

    public function canViewSensitive(User $actor): bool
    {
        return $actor->is_active && $actor->can('payroll.view') && $actor->can('payroll.view_sensitive');
    }

    public function canExport(User $actor): bool
    {
        return $this->canViewSensitive($actor) && $actor->can('payroll_reports.export');
    }

    /** Whether the user may open the report at all. */
    public function canOpen(User $actor, string $report): bool
    {
        return $actor->is_active && $actor->can('payroll.view') && (! in_array($report, self::AMOUNT_ONLY, true) || $this->canViewSensitive($actor));
    }

    /**
     * Build one report for the screen.
     *
     * @param  array<string, mixed>  $input
     * @return array{report: string, title: string, sensitive: bool, table: ?ReportTable, paginator: ?LengthAwarePaginator, filters: list<array{name: string, label: string, value: string, options: array<string, string>}>, prompt: ?string}
     */
    public function build(string $report, User $actor, array $input, ?int $perPage = 20): array
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless($actor->is_active && $actor->can('payroll.view'), 403);
        $sensitive = $this->canViewSensitive($actor);
        abort_if(in_array($report, self::AMOUNT_ONLY, true) && ! $sensitive, 403, 'This payroll report shows salary amounts and needs the sensitive payroll permission.');

        $scope = $this->scope($actor, $input['branch_id'] ?? null);
        $result = match ($report) {
            'register' => $this->register($actor, $input, $scope),
            'employee-history' => $this->employeeHistory($actor, $input, $scope, $sensitive, $perPage),
            'run-summary' => $this->runSummary($input, $scope, $sensitive, $perPage),
            'accrual-summary' => $this->accrualSummary($input, $scope, $sensitive, $perPage),
            'payment-status' => $this->paymentStatus($input, $scope, $sensitive, $perPage),
            'deductions' => $this->deductions($input, $scope),
            'import-errors' => $this->importErrors($actor, $input, $scope, $sensitive, $perPage),
            'advances-outstanding' => $this->advancesOutstanding($input, $scope),
        };

        return [
            'report' => $report, 'title' => self::REPORTS[$report], 'sensitive' => $sensitive,
            'table' => $result['table'] ?? null, 'paginator' => $result['paginator'] ?? null,
            'filters' => [$this->branchFilter($actor, $scope, $input), ...($result['filters'] ?? [])], 'prompt' => $result['prompt'] ?? null,
        ];
    }

    /**
     * The complete, unpaginated table for a download. Exports always contain
     * amounts, so all three permissions are required for every report.
     *
     * @param  array<string, mixed>  $input
     */
    public function exportTable(string $report, User $actor, array $input): ReportTable
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless($actor->is_active && $actor->can('payroll.view') && $actor->can('payroll.view_sensitive') && $actor->can('payroll_reports.export'), 403);
        $table = $this->build($report, $actor, $input, null)['table'];
        abort_if($table === null, 404, 'Select what to report before exporting.');

        return $table;
    }

    // --- Reports -----------------------------------------------------------------

    /**
     * Staff advances and loans with their stored recovery: principal, amount
     * recovered through posted payroll runs and the outstanding balance.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function advancesOutstanding(array $input, array $scope): array
    {
        $statuses = [StaffAdvanceStatus::Approved, StaffAdvanceStatus::Active, StaffAdvanceStatus::Settled];
        $selected = is_string($input['status'] ?? null) ? StaffAdvanceStatus::tryFrom($input['status']) : null;
        $selected = in_array($selected, $statuses, true) ? $selected : null;
        $listed = $selected ? [$selected] : [StaffAdvanceStatus::Approved, StaffAdvanceStatus::Active];
        $employees = Employee::query()->whereIn('branch_id', $scope['ids'])->whereHas('staffAdvances')->orderBy('employee_code')->get(['id', 'employee_code', 'name']);
        $filters = [
            $this->filter('status', 'Advance status', $input, ['' => 'Approved and active'] + collect($statuses)->mapWithKeys(fn (StaffAdvanceStatus $status): array => [$status->value => $status->label()])->all()),
            $this->filter('employee_id', 'Employee', $input, ['' => 'All employees'] + $employees->mapWithKeys(fn (Employee $employee): array => [(string) $employee->id => $employee->employee_code.' — '.$employee->name])->all()),
        ];

        $advances = StaffAdvance::query()->with(['employee:id,employee_code,name', 'branch:id,name', 'instalments.period:id,name'])
            ->whereIn('branch_id', $scope['ids'])->whereIn('status', array_map(fn (StaffAdvanceStatus $status): string => $status->value, $listed))
            ->when(filled($input['employee_id'] ?? null), fn (Builder $query) => $query->where('employee_id', (int) $input['employee_id']))
            ->orderBy('branch_id')->orderBy('id')->get();

        $zero = BigDecimal::zero()->toScale(4);
        $totals = ['principal' => $zero, 'recovered' => $zero, 'outstanding' => $zero];
        $rows = [];
        foreach ($advances as $advance) {
            $open = $advance->instalments->filter(fn ($instalment): bool => AccountingDecimal::of($instalment->outstandingAmount())->isPositive());
            $row = [
                'reference' => (string) $advance->reference, 'employee_code' => (string) $advance->employee->employee_code, 'employee' => (string) $advance->employee->name,
                'branch' => (string) $advance->branch->name, 'type' => $advance->type->label(), 'status' => $advance->status->label(),
                'next_due' => (string) ($open->first()?->period->name ?? '—'), 'open_instalments' => (string) $open->count(),
                'principal' => AccountingDecimal::string(AccountingDecimal::of($advance->principal_amount)), 'recovered' => $advance->recoveredAmount(), 'outstanding' => $advance->outstandingAmount(),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] = $totals[$key]->plus(AccountingDecimal::of($row[$key]));
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            $rows[] = ['reference' => 'Total', 'employee_code' => '', 'employee' => count($advances).' advances', 'branch' => '', 'type' => '', 'status' => '', 'next_due' => '', 'open_instalments' => '', '_style' => 'total',
                ...array_map(fn (BigDecimal $sum): string => AccountingDecimal::string($sum), $totals)];
        }
        $columns = [
            ['key' => 'reference', 'label' => 'Reference'], ['key' => 'employee_code', 'label' => 'Employee code'], ['key' => 'employee', 'label' => 'Employee'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'next_due', 'label' => 'Next due period'], ['key' => 'open_instalments', 'label' => 'Open instalments', 'type' => 'number'],
            ...$this->amountColumns(['principal' => 'Principal', 'recovered' => 'Recovered', 'outstanding' => 'Outstanding']),
        ];

        return ['filters' => $filters, 'table' => new ReportTable(self::REPORTS['advances-outstanding'], $columns, $rows, [
            'Branch' => $scope['label'], 'Status' => implode(', ', array_map(fn (StaffAdvanceStatus $status): string => $status->label(), $listed)),
        ], 'Recovered amounts come only from posted payroll runs that deduct the recovery component. Advance and loan rules are not approved; no interest or rule is applied.')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function register(User $actor, array $input, array $scope): array
    {
        $filters = [$this->runFilter($scope, $input, null)];
        $run = $this->selectedRun($actor, $input);
        if (! $run) {
            return ['filters' => $filters, 'prompt' => 'Select a payroll run to see its register.'];
        }

        $lines = $run->lines()->with('employee:id,employee_code,name,designation')->get()->sortBy(fn (PayrollRunLine $line): string => (string) $line->employee?->employee_code)->values();
        $items = DB::table('payroll_run_line_items')->join('payroll_run_lines', 'payroll_run_lines.id', '=', 'payroll_run_line_items.payroll_run_line_id')
            ->where('payroll_run_lines.payroll_run_id', $run->id)
            ->get(['payroll_run_line_items.payroll_run_line_id as line_id', 'payroll_run_line_items.payroll_component_id as component_id', 'payroll_run_line_items.amount']);
        $components = $this->orderedComponents($items->pluck('component_id')->unique()->all());
        $byLine = [];
        foreach ($items as $item) {
            $byLine[(int) $item->line_id][(int) $item->component_id] = AccountingDecimal::of($item->amount);
        }

        $columns = [['key' => 'employee_code', 'label' => 'Employee code'], ['key' => 'employee', 'label' => 'Employee'], ['key' => 'designation', 'label' => 'Designation']];
        foreach ($components as $component) {
            $columns[] = ['key' => 'c'.$component->id, 'label' => $component->name.' ('.($component->isEarning() ? 'earning' : 'deduction').')', 'type' => 'money'];
        }
        $columns = [...$columns, ...$this->amountColumns(['gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net pay'])];

        $zero = BigDecimal::zero()->toScale(4);
        $totals = ['gross' => $zero, 'deductions' => $zero, 'net' => $zero];
        $componentTotals = [];
        $rows = [];
        foreach ($lines as $line) {
            $row = ['employee_code' => (string) $line->employee?->employee_code, 'employee' => (string) $line->employee?->name, 'designation' => (string) $line->employee?->designation];
            foreach ($components as $component) {
                $amount = $byLine[$line->id][$component->id] ?? null;
                $row['c'.$component->id] = $amount ? AccountingDecimal::string($amount) : null;
                $componentTotals[$component->id] = ($componentTotals[$component->id] ?? $zero)->plus($amount ?? $zero);
            }
            foreach (['gross' => 'gross_amount', 'deductions' => 'total_deductions', 'net' => 'net_amount'] as $key => $field) {
                $row[$key] = (string) $line->{$field};
                $totals[$key] = $totals[$key]->plus(AccountingDecimal::of($line->{$field}));
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            $total = ['employee_code' => 'Total', 'employee' => $lines->count().' employees', 'designation' => '', '_style' => 'total'];
            foreach ($components as $component) {
                $total['c'.$component->id] = AccountingDecimal::string($componentTotals[$component->id]);
            }
            $rows[] = [...$total, ...array_map(fn (BigDecimal $sum): string => AccountingDecimal::string($sum), $totals)];
        }

        return ['filters' => $filters, 'table' => new ReportTable(self::REPORTS['register'], $columns, $rows, [
            'Branch' => $run->branch->name, 'Period' => $run->period->name, 'Run' => '#'.$run->id.' ('.$run->status->label().')',
        ], 'Stored amounts of the payroll run. No tax, statutory or leave figure is calculated by the system.')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function employeeHistory(User $actor, array $input, array $scope, bool $sensitive, ?int $perPage): array
    {
        $employees = Employee::query()->whereIn('branch_id', $scope['ids'])->orderBy('employee_code')->get(['id', 'employee_code', 'name']);
        $filters = [$this->filter('employee_id', 'Employee', $input, ['' => 'Select employee'] + $employees->mapWithKeys(fn (Employee $employee): array => [(string) $employee->id => $employee->employee_code.' — '.$employee->name])->all())];
        if (blank($input['employee_id'] ?? null)) {
            return ['filters' => $filters, 'prompt' => 'Select an employee to see the payroll runs they were part of.'];
        }
        $employee = Employee::query()->with('branch')->findOrFail((int) $input['employee_id']);
        abort_unless($this->branchAccess->canAccess($actor, $employee->branch), 403);

        $query = PayrollRunLine::query()->with(['run.period', 'run.branch'])->where('payroll_run_lines.employee_id', $employee->id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.payroll_run_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'payroll_runs.accounting_period_id')
            ->whereIn('payroll_runs.branch_id', $this->accessibleBranchIds($actor))
            ->orderByDesc('accounting_periods.start_date')->orderByDesc('payroll_runs.id')
            // Amount columns are not read from the database without the sensitive permission.
            ->select($sensitive ? ['payroll_run_lines.*'] : ['payroll_run_lines.id', 'payroll_run_lines.payroll_run_id', 'payroll_run_lines.employee_id']);
        [$lines, $paginator] = $this->fetch($query, $perPage);

        $rows = $lines->map(fn (PayrollRunLine $line): array => [
            'period' => $line->run->period->name, 'branch' => $line->run->branch->name, 'run' => '#'.$line->run->id, 'status' => $line->run->status->label(),
            ...($sensitive ? ['gross' => (string) $line->gross_amount, 'deductions' => (string) $line->total_deductions, 'net' => (string) $line->net_amount] : []),
        ])->all();

        return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['employee-history'], [
            ['key' => 'period', 'label' => 'Period'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'run', 'label' => 'Run'], ['key' => 'status', 'label' => 'Run status'],
            ...($sensitive ? $this->amountColumns(['gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net pay']) : []),
        ], $rows, ['Employee' => $employee->employee_code.' — '.$employee->name, 'Branch' => $employee->branch->name],
            'Runs of every status are listed, including rejected and reversed runs; rows are not totalled for that reason.')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function runSummary(array $input, array $scope, bool $sensitive, ?int $perPage): array
    {
        $statuses = collect(PayrollRunStatus::cases());
        $filters = [$this->periodFilter($input), $this->statusFilter($input, $statuses)];
        $base = $this->runs($scope, $input, $statuses);
        [$runs, $paginator] = $this->fetch((clone $base)->with(['branch', 'period', 'journalEntry:id,voucher_number'])
            ->select($sensitive ? ['payroll_runs.*'] : ['payroll_runs.id', 'payroll_runs.branch_id', 'payroll_runs.accounting_period_id', 'payroll_runs.status', 'payroll_runs.source', 'payroll_runs.journal_entry_id'])
            ->withCount('lines')
            ->orderByDesc('payroll_runs.accounting_period_id')->orderBy('payroll_runs.branch_id')->orderByDesc('payroll_runs.id'), $perPage);

        $rows = $runs->map(fn (PayrollRun $run): array => [
            'period' => $run->period->name, 'branch' => $run->branch->name, 'run' => '#'.$run->id, 'status' => $run->status->label(), 'source' => ucfirst((string) $run->source),
            'employees' => (string) $run->lines_count, 'voucher' => (string) $run->journalEntry?->voucher_number,
            ...($sensitive ? ['gross' => (string) $run->gross_amount, 'deductions' => (string) $run->total_deductions, 'net' => (string) $run->net_amount] : []),
        ])->all();

        if ($sensitive) {
            // Totals cover the whole filtered set, one line per status, so rejected or reversed runs are never mixed into a payable figure.
            $sums = (clone $base)->groupBy('payroll_runs.status')
                ->selectRaw('payroll_runs.status as status, COUNT(*) as runs, SUM(payroll_runs.gross_amount) as gross, SUM(payroll_runs.total_deductions) as deductions, SUM(payroll_runs.net_amount) as net')->toBase()->get()->keyBy('status');
            foreach ($statuses as $status) {
                if ($sum = $sums->get($status->value)) {
                    $rows[] = ['period' => 'Total — '.$status->label(), 'branch' => '', 'run' => $sum->runs.' '.str('run')->plural((int) $sum->runs), 'status' => $status->label(), 'source' => '', 'employees' => null, 'voucher' => '',
                        'gross' => $this->sum($sum->gross), 'deductions' => $this->sum($sum->deductions), 'net' => $this->sum($sum->net), '_style' => 'total'];
                }
            }
        }

        return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['run-summary'], [
            ['key' => 'period', 'label' => 'Period'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'run', 'label' => 'Run'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'source', 'label' => 'Source'], ['key' => 'employees', 'label' => 'Employees', 'type' => 'number'], ['key' => 'voucher', 'label' => 'Accrual voucher'],
            ...($sensitive ? $this->amountColumns(['gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net pay']) : []),
        ], $rows, $this->meta($scope, $input, $statuses), $sensitive ? 'Totals are given per run status and cover every run of the selection.' : null)];
    }

    /**
     * Posted and paid runs with their accrual voucher. Salary expense is the
     * debit total of the posted journal; payable and deduction liabilities are
     * the stored net and deduction totals the journal was built from.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function accrualSummary(array $input, array $scope, bool $sensitive, ?int $perPage): array
    {
        $statuses = collect(self::ACCRUED_STATUSES);
        $filters = [$this->periodFilter($input), $this->statusFilter($input, $statuses)];
        $base = $this->runs($scope, $input, $statuses)->whereNotNull('payroll_runs.journal_entry_id');
        [$runs, $paginator] = $this->fetch((clone $base)->with(['branch', 'period', 'journalEntry:id,voucher_number,transaction_date,status'])
            ->select($sensitive ? ['payroll_runs.*'] : ['payroll_runs.id', 'payroll_runs.branch_id', 'payroll_runs.accounting_period_id', 'payroll_runs.status', 'payroll_runs.journal_entry_id'])
            ->orderByDesc('payroll_runs.accounting_period_id')->orderBy('payroll_runs.branch_id')->orderByDesc('payroll_runs.id'), $perPage);

        $rows = [];
        if ($sensitive) {
            $expense = DB::table('journal_lines')->whereIn('journal_entry_id', (clone $base)->select('payroll_runs.journal_entry_id'))
                ->groupBy('journal_entry_id')->selectRaw('journal_entry_id, SUM(debit) as debit')->pluck('debit', 'journal_entry_id');
        }
        $zero = BigDecimal::zero()->toScale(4);
        foreach ($runs as $run) {
            $rows[] = [
                'period' => $run->period->name, 'branch' => $run->branch->name, 'run' => '#'.$run->id, 'status' => $run->status->label(),
                'voucher' => (string) $run->journalEntry?->voucher_number, 'voucher_date' => (string) $run->journalEntry?->transaction_date?->format('d M Y'),
                ...($sensitive ? ['expense' => $this->sum($expense[$run->journal_entry_id] ?? null), 'payable' => (string) $run->net_amount, 'liabilities' => (string) $run->total_deductions] : []),
            ];
        }
        if ($sensitive && $rows !== []) {
            $totals = (clone $base)->selectRaw('COUNT(*) as runs, SUM(payroll_runs.net_amount) as net, SUM(payroll_runs.total_deductions) as deductions')->toBase()->first();
            $rows[] = ['period' => 'Total', 'branch' => '', 'run' => $totals->runs.' '.str('run')->plural((int) $totals->runs), 'status' => '', 'voucher' => '', 'voucher_date' => '',
                'expense' => AccountingDecimal::string($expense->reduce(fn (BigDecimal $carry, mixed $debit): BigDecimal => $carry->plus($this->sum($debit)), $zero)),
                'payable' => $this->sum($totals->net), 'liabilities' => $this->sum($totals->deductions), '_style' => 'total'];
        }

        return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['accrual-summary'], [
            ['key' => 'period', 'label' => 'Period'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'run', 'label' => 'Run'], ['key' => 'status', 'label' => 'Run status'],
            ['key' => 'voucher', 'label' => 'Accrual voucher'], ['key' => 'voucher_date', 'label' => 'Voucher date'],
            ...($sensitive ? $this->amountColumns(['expense' => 'Salary expense (posted)', 'payable' => 'Net salary payable', 'liabilities' => 'Deduction liabilities']) : []),
        ], $rows, $this->meta($scope, $input, $statuses), 'Posted and paid runs only. A reversed run is not an accrual and is listed in the Payroll Run Summary.')];
    }

    /**
     * Net salary against the linked payments. Paid counts posted payments only;
     * a payment still in its workflow is shown as in progress; a rejected or
     * reversed payment is listed with its status and counts for nothing.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function paymentStatus(array $input, array $scope, bool $sensitive, ?int $perPage): array
    {
        $statuses = collect(self::ACCRUED_STATUSES);
        $filters = [$this->periodFilter($input), $this->statusFilter($input, $statuses)];
        $transactionColumns = $sensitive ? 'transaction' : 'transaction:id,type,status,transaction_date,reference_number';
        $base = $this->runs($scope, $input, $statuses);
        [$runs, $paginator] = $this->fetch((clone $base)->with(['branch', 'period', 'payments.'.$transactionColumns])
            ->select($sensitive ? ['payroll_runs.*'] : ['payroll_runs.id', 'payroll_runs.branch_id', 'payroll_runs.accounting_period_id', 'payroll_runs.status'])
            ->orderByDesc('payroll_runs.accounting_period_id')->orderBy('payroll_runs.branch_id')->orderByDesc('payroll_runs.id'), $perPage);

        $zero = BigDecimal::zero()->toScale(4);
        $rows = [];
        foreach ($runs as $run) {
            $figures = $sensitive ? $this->paymentFigures($run) : [];
            $rows[] = ['item' => $run->period->name.' — run #'.$run->id, 'branch' => $run->branch->name, 'status' => $run->status->label(), 'reference' => '', 'date' => '',
                ...($sensitive ? ['net' => (string) $run->net_amount, 'payment' => null, ...array_map(fn (BigDecimal $value): string => AccountingDecimal::string($value), $figures)] : []), '_style' => 'subtotal'];
            foreach ($run->payments as $payment) {
                $transaction = $payment->transaction;
                $rows[] = ['item' => 'Payment #'.$transaction->id.' ('.$transaction->type->label().')', 'branch' => '', 'status' => ucfirst($transaction->status->value),
                    'reference' => (string) $transaction->reference_number, 'date' => (string) $transaction->transaction_date?->format('d M Y'),
                    ...($sensitive ? ['net' => null, 'payment' => (string) $transaction->amount, 'paid' => null, 'in_progress' => null, 'outstanding' => null] : []), '_indent' => 1];
            }
        }
        if ($sensitive && $rows !== []) {
            // Totals cover every run of the selection, not only the page shown.
            $totals = ['net' => $zero, 'paid' => $zero, 'in_progress' => $zero, 'outstanding' => $zero];
            (clone $base)->with('payments.transaction:id,status,amount')->select(['payroll_runs.id', 'payroll_runs.net_amount'])->orderBy('payroll_runs.id')
                ->chunk(200, function (Collection $chunk) use (&$totals): void {
                    foreach ($chunk as $run) {
                        $totals['net'] = $totals['net']->plus(AccountingDecimal::of($run->net_amount));
                        foreach ($this->paymentFigures($run) as $key => $value) {
                            $totals[$key] = $totals[$key]->plus($value);
                        }
                    }
                });
            $rows[] = ['item' => 'Total', 'branch' => '', 'status' => '', 'reference' => '', 'date' => '', 'net' => AccountingDecimal::string($totals['net']), 'payment' => null,
                'paid' => AccountingDecimal::string($totals['paid']), 'in_progress' => AccountingDecimal::string($totals['in_progress']), 'outstanding' => AccountingDecimal::string($totals['outstanding']), '_style' => 'total'];
        }

        return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['payment-status'], [
            ['key' => 'item', 'label' => 'Run / linked payment'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'status', 'label' => 'Run status / payment workflow status'],
            ['key' => 'reference', 'label' => 'Reference'], ['key' => 'date', 'label' => 'Payment date'],
            ...($sensitive ? $this->amountColumns(['net' => 'Net salary', 'payment' => 'Payment amount', 'paid' => 'Paid (posted payments)', 'in_progress' => 'In progress (not posted)', 'outstanding' => 'Outstanding (net − paid)']) : []),
        ], $rows, $this->meta($scope, $input, $statuses), 'Paid counts posted payments only. Rejected and reversed payments are listed with their status and are not counted.')];
    }

    /**
     * Stored deduction amounts by period and component.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function deductions(array $input, array $scope): array
    {
        $statuses = collect(PayrollRunStatus::cases());
        $default = collect(self::ACCRUED_STATUSES);
        $filters = [$this->periodFilter($input), $this->statusFilter($input, $statuses, 'Posted and paid runs')];
        $selected = $this->selectedStatus($input, $statuses);

        $sums = DB::table('payroll_run_line_items')
            ->join('payroll_run_lines', 'payroll_run_lines.id', '=', 'payroll_run_line_items.payroll_run_line_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.payroll_run_id')
            ->join('payroll_components', 'payroll_components.id', '=', 'payroll_run_line_items.payroll_component_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'payroll_runs.accounting_period_id')
            ->where('payroll_components.type', PayrollComponentType::Deduction->value)
            ->whereIn('payroll_runs.branch_id', $scope['ids'])
            ->whereIn('payroll_runs.status', ($selected ? collect([$selected]) : $default)->map(fn (PayrollRunStatus $status): string => $status->value)->all())
            ->when(filled($input['period_id'] ?? null), fn ($query) => $query->where('payroll_runs.accounting_period_id', (int) $input['period_id']))
            ->groupBy('accounting_periods.id', 'accounting_periods.name', 'accounting_periods.start_date', 'payroll_components.id', 'payroll_components.code', 'payroll_components.name', 'payroll_components.category', 'payroll_components.sort_order')
            ->orderByDesc('accounting_periods.start_date')->orderBy('payroll_components.sort_order')->orderBy('payroll_components.id')
            ->selectRaw('accounting_periods.name as period, payroll_components.code as code, payroll_components.name as component, payroll_components.category as category, COUNT(*) as entries, SUM(payroll_run_line_items.amount) as amount')
            ->get();

        $total = BigDecimal::zero()->toScale(4);
        $rows = [];
        foreach ($sums as $sum) {
            $amount = $this->sum($sum->amount);
            $total = $total->plus($amount);
            $rows[] = ['period' => $sum->period, 'code' => $sum->code, 'component' => $sum->component, 'category' => str((string) $sum->category)->replace('_', ' ')->title()->toString(), 'entries' => (string) $sum->entries, 'amount' => $amount];
        }
        if ($rows !== []) {
            $rows[] = ['period' => 'Total', 'code' => '', 'component' => '', 'category' => '', 'entries' => null, 'amount' => AccountingDecimal::string($total), '_style' => 'total'];
        }

        return ['filters' => $filters, 'table' => new ReportTable(self::REPORTS['deductions'], [
            ['key' => 'period', 'label' => 'Period'], ['key' => 'code', 'label' => 'Component code'], ['key' => 'component', 'label' => 'Deduction component'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'entries', 'label' => 'Employee lines', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount deducted', 'type' => 'money'],
        ], $rows, [...$this->meta($scope, $input, $statuses), 'Run status' => $selected?->label() ?? 'Posted and paid runs'],
            'Sums of the deduction amounts stored on payroll run lines. The system applies no tax, statutory or leave formula.')];
    }

    /**
     * Rows of one payroll import that carry an error or a warning. Cell values
     * and amounts are released only with payroll.view_sensitive; without it the
     * encrypted values are not read and numbers inside messages are masked.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function importErrors(User $actor, array $input, array $scope, bool $sensitive, ?int $perPage): array
    {
        $imports = PayrollImport::query()->with(['branch', 'period'])->whereIn('branch_id', $scope['ids'])->orderByDesc('id');
        $filters = [$this->filter('import_id', 'Import', $input, ['' => 'All imports (list)'] + (clone $imports)->limit(200)->get()
            ->mapWithKeys(fn (PayrollImport $import): array => [(string) $import->id => '#'.$import->id.' — '.$import->period->name.' — '.$import->branch->name.' ('.$import->status->label().')'])->all())];

        if (blank($input['import_id'] ?? null)) {
            [$list, $paginator] = $this->fetch($imports, $perPage);
            $rows = $list->map(fn (PayrollImport $import): array => [
                'import' => '#'.$import->id, 'file' => $import->original_filename, 'branch' => $import->branch->name, 'period' => $import->period->name, 'status' => $import->status->label(),
                'uploaded' => (string) $import->created_at?->format('d M Y H:i'), 'rows' => $this->count($import->summary['total_rows'] ?? null),
                'error_rows' => $this->count($import->summary['error_rows'] ?? null), 'warning_rows' => $this->count($import->summary['warning_rows'] ?? null),
            ])->all();

            return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['import-errors'], [
                ['key' => 'import', 'label' => 'Import'], ['key' => 'file', 'label' => 'File'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'period', 'label' => 'Period'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'uploaded', 'label' => 'Uploaded'], ['key' => 'rows', 'label' => 'Rows', 'type' => 'number'], ['key' => 'error_rows', 'label' => 'Rows with errors', 'type' => 'number'], ['key' => 'warning_rows', 'label' => 'Rows with warnings', 'type' => 'number'],
            ], $rows, ['Branch' => $scope['label']], 'Row counts are known once an import has been validated. Select an import to see its row messages.')];
        }

        $import = PayrollImport::query()->with(['branch', 'period'])->findOrFail((int) $input['import_id']);
        abort_unless($this->branchAccess->canAccess($actor, $import->branch), 403);

        $query = PayrollImportRow::query()->where('payroll_import_id', $import->id)
            ->where(fn (Builder $builder) => $builder->whereJsonLength('errors', '>', 0)->orWhereJsonLength('warnings', '>', 0))
            ->orderBy('row_number')
            // The encrypted cell values are not even read without the sensitive permission.
            ->select($sensitive ? ['*'] : ['id', 'payroll_import_id', 'row_number', 'errors', 'warnings', 'is_excluded']);
        [$importRows, $paginator] = $this->fetch($query, $perPage);

        $rows = [];
        foreach ($import->summary['import_errors'] ?? [] as $message) {
            $rows[] = ['row' => 'File', 'status' => 'Error', ...($sensitive ? ['employee_code' => '', 'employee_name' => '', 'gross' => null, 'deductions' => null, 'net' => null] : []), 'errors' => $this->message((string) $message, $sensitive), 'warnings' => '', '_style' => 'heading'];
        }
        foreach ($importRows as $row) {
            $values = $sensitive ? ($row->mapped_values ?? []) : [];
            $rows[] = [
                'row' => (string) $row->row_number, 'status' => $row->is_excluded ? 'Excluded' : ($row->hasErrors() ? 'Error' : 'Warning'),
                ...($sensitive ? [
                    'employee_code' => (string) ($values['employee_code'] ?? ''), 'employee_name' => (string) ($values['employee_name'] ?? ''),
                    'gross' => $values['gross'] ?? null, 'deductions' => $values['deductions'] ?? null, 'net' => $values['net'] ?? null,
                ] : []),
                'errors' => collect($row->errors ?? [])->map(fn (mixed $message): string => $this->message((string) $message, $sensitive))->implode(' | '),
                'warnings' => collect($row->warnings ?? [])->map(fn (mixed $message): string => $this->message((string) $message, $sensitive))->implode(' | '),
            ];
        }

        return ['filters' => $filters, 'paginator' => $paginator, 'table' => new ReportTable(self::REPORTS['import-errors'], [
            ['key' => 'row', 'label' => 'File row'], ['key' => 'status', 'label' => 'Status'],
            ...($sensitive ? [['key' => 'employee_code', 'label' => 'Employee code (file)'], ['key' => 'employee_name', 'label' => 'Employee name (file)'], ...$this->amountColumns(['gross' => 'Gross (file)', 'deductions' => 'Deductions (file)', 'net' => 'Net (file)'])] : []),
            ['key' => 'errors', 'label' => 'Errors'], ['key' => 'warnings', 'label' => 'Warnings'],
        ], $rows, ['Import' => '#'.$import->id.' — '.$import->original_filename, 'Branch' => $import->branch->name, 'Period' => $import->period->name, 'Status' => $import->status->label()],
            $sensitive ? 'Only rows with an error or a warning are listed.' : 'Only rows with an error or a warning are listed. Cell values and amounts are hidden: numbers inside messages are replaced with '.self::HIDDEN.'.')];
    }

    // --- Shared helpers ----------------------------------------------------------

    /**
     * The branches a report covers. A named branch outside the user's scope is
     * refused, and the consolidated all-branch view needs branches.view_all.
     *
     * @return array{ids: list<int>, label: string, options: array<string, string>, selected: string}
     */
    private function scope(User $actor, mixed $requested): array
    {
        $branches = $this->branchAccess->scope($actor)->orderBy('name')->get(['id', 'name']);
        $viewAll = $actor->can('branches.view_all');
        $options = ['' => $viewAll ? 'All branches' : 'All my branches'] + $branches->mapWithKeys(fn (Branch $branch): array => [(string) $branch->id => $branch->name])->all();

        if ($requested === 'all') {
            abort_unless($viewAll, 403, 'A consolidated all-branch payroll view needs the all-branch permission.');
            $requested = null;
        }
        if (filled($requested)) {
            abort_unless(is_scalar($requested) && ctype_digit((string) $requested), 404);
            $branch = Branch::query()->findOrFail((int) $requested);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

            return ['ids' => [(int) $branch->id], 'label' => $branch->name, 'options' => $options, 'selected' => (string) $branch->id];
        }

        return ['ids' => $branches->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'label' => $viewAll ? 'All branches' : 'All branches assigned to the user', 'options' => $options, 'selected' => ''];
    }

    /** @return list<int> */
    private function accessibleBranchIds(User $actor): array
    {
        return $this->branchAccess->scope($actor)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PayrollRunStatus>  $allowed
     * @return Builder<PayrollRun>
     */
    private function runs(array $scope, array $input, Collection $allowed): Builder
    {
        $selected = $this->selectedStatus($input, $allowed);

        return PayrollRun::query()->whereIn('payroll_runs.branch_id', $scope['ids'])
            ->whereIn('payroll_runs.status', ($selected ? collect([$selected]) : $allowed)->map(fn (PayrollRunStatus $status): string => $status->value)->all())
            ->when(filled($input['period_id'] ?? null), fn (Builder $query) => $query->where('payroll_runs.accounting_period_id', (int) $input['period_id']));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PayrollRunStatus>  $allowed
     */
    private function selectedStatus(array $input, Collection $allowed): ?PayrollRunStatus
    {
        $status = is_string($input['status'] ?? null) ? PayrollRunStatus::tryFrom($input['status']) : null;

        return $status && $allowed->contains($status) ? $status : null;
    }

    /** @param array<string, mixed> $input */
    private function selectedRun(User $actor, array $input): ?PayrollRun
    {
        if (blank($input['run_id'] ?? null)) {
            return null;
        }
        $run = PayrollRun::query()->with(['branch', 'period'])->findOrFail((int) $input['run_id']);
        abort_unless($this->branchAccess->canAccess($actor, $run->branch), 403);

        return $run;
    }

    /**
     * Net salary settled, still in the payment workflow, and not yet paid.
     *
     * @return array{paid: BigDecimal, in_progress: BigDecimal, outstanding: BigDecimal}
     */
    private function paymentFigures(PayrollRun $run): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        [$paid, $inProgress] = [$zero, $zero];
        foreach ($run->payments as $payment) {
            /** @var PayrollRunPayment $payment */
            $status = $payment->transaction->status;
            if (in_array($status, [OperationalTransactionStatus::Rejected, OperationalTransactionStatus::Reversed], true)) {
                continue;
            }
            $amount = AccountingDecimal::of($payment->transaction->amount);
            if ($status === OperationalTransactionStatus::Posted) {
                $paid = $paid->plus($amount);
            } else {
                $inProgress = $inProgress->plus($amount);
            }
        }

        return ['paid' => $paid, 'in_progress' => $inProgress, 'outstanding' => AccountingDecimal::of($run->net_amount)->minus($paid)];
    }

    /**
     * Earnings first, then deductions, each in their configured order.
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, PayrollComponent>
     */
    private function orderedComponents(array $ids): Collection
    {
        return PayrollComponent::query()->whereKey($ids)->get()
            ->sortBy(fn (PayrollComponent $component): array => [$component->isEarning() ? 0 : 1, $component->sort_order, $component->id])->values();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array{0: Collection<int, mixed>, 1: ?LengthAwarePaginator}
     */
    private function fetch(Builder $query, ?int $perPage): array
    {
        if ($perPage === null) {
            return [$query->get(), null];
        }
        $paginator = $query->paginate($perPage)->withQueryString();

        return [collect($paginator->items()), $paginator];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string, type: string}>
     */
    private function amountColumns(array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'type' => 'money'])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PayrollRunStatus>  $allowed
     * @return array<string, string>
     */
    private function meta(array $scope, array $input, Collection $allowed): array
    {
        $period = filled($input['period_id'] ?? null) ? AccountingPeriod::query()->find((int) $input['period_id']) : null;

        return ['Branch' => $scope['label'], 'Period' => $period?->name ?? 'All periods', 'Run status' => $this->selectedStatus($input, $allowed)?->label() ?? $allowed->map(fn (PayrollRunStatus $status): string => $status->label())->implode(', ')];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $input
     * @return array{name: string, label: string, value: string, options: array<string, string>}
     */
    private function branchFilter(User $actor, array $scope, array $input): array
    {
        return ['name' => 'branch_id', 'label' => 'Branch', 'value' => $scope['selected'], 'options' => $scope['options']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, label: string, value: string, options: array<string, string>}
     */
    private function periodFilter(array $input): array
    {
        $periods = AccountingPeriod::query()->whereIn('id', PayrollRun::query()->select('accounting_period_id'))->orderByDesc('start_date')->get(['id', 'name']);

        return $this->filter('period_id', 'Period', $input, ['' => 'All periods'] + $periods->mapWithKeys(fn (AccountingPeriod $period): array => [(string) $period->id => $period->name])->all());
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PayrollRunStatus>  $statuses
     * @return array{name: string, label: string, value: string, options: array<string, string>}
     */
    private function statusFilter(array $input, Collection $statuses, string $allLabel = 'All listed statuses'): array
    {
        return $this->filter('status', 'Run status', $input, ['' => $allLabel] + $statuses->mapWithKeys(fn (PayrollRunStatus $status): array => [$status->value => $status->label()])->all());
    }

    /**
     * Runs the user may pick for the register.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $input
     * @return array{name: string, label: string, value: string, options: array<string, string>}
     */
    private function runFilter(array $scope, array $input, ?string $unused): array
    {
        $runs = PayrollRun::query()->with(['branch:id,name', 'period:id,name'])->whereIn('branch_id', $scope['ids'])
            ->orderByDesc('accounting_period_id')->orderByDesc('id')->limit(300)->get(['id', 'branch_id', 'accounting_period_id', 'status']);

        return $this->filter('run_id', 'Payroll run', $input, ['' => 'Select payroll run'] + $runs->mapWithKeys(fn (PayrollRun $run): array => [(string) $run->id => $run->period->name.' — '.$run->branch->name.' — #'.$run->id.' ('.$run->status->label().')'])->all());
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $options
     * @return array{name: string, label: string, value: string, options: array<string, string>}
     */
    private function filter(string $name, string $label, array $input, array $options): array
    {
        return ['name' => $name, 'label' => $label, 'value' => is_scalar($input[$name] ?? null) ? (string) $input[$name] : '', 'options' => $options];
    }

    /** Numbers inside an import message can be salary figures, so they are masked without the sensitive permission. */
    private function message(string $message, bool $sensitive): string
    {
        return $sensitive ? $message : (string) preg_replace('/\d[\d,]*(?:\.\d+)?/', self::HIDDEN, $message);
    }

    private function count(mixed $value): ?string
    {
        return is_numeric($value) ? (string) (int) $value : null;
    }

    /** A database SUM of DECIMAL(19,4) columns as an exact four-decimal string. */
    private function sum(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }
}
