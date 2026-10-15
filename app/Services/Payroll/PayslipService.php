<?php

namespace App\Services\Payroll;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunLineItem;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Payslips are a per-employee view of the payroll register: the stored
 * component amounts and totals of an approved, posted or paid run line.
 * Nothing is calculated beyond summing the stored items, and nothing is
 * written. Every payslip needs payroll.view_sensitive and branch access.
 */
class PayslipService
{
    /** Runs whose figures are final enough to issue payslips. */
    public const ISSUABLE_STATUSES = [PayrollRunStatus::Approved, PayrollRunStatus::Posted, PayrollRunStatus::Paid];

    public function __construct(private BranchAccess $branchAccess, private EmployeeService $employees, private PayrollRunService $runs) {}

    public function isIssuable(PayrollRun $run): bool
    {
        return in_array($run->status, self::ISSUABLE_STATUSES, true);
    }

    /**
     * Payslips of a run, or of one line of it.
     *
     * @return array{run: PayrollRun, paymentStatus: string, payslips: Collection<int, array{line: PayrollRunLine, earnings: list<array{name: string, amount: string}>, deductions: list<array{name: string, amount: string}>, gross: string, deductions_total: string, net: string}>}
     */
    public function build(PayrollRun $run, User $actor, ?PayrollRunLine $only = null): array
    {
        abort_unless($this->employees->canViewSensitive($actor) && $actor->can('payroll.view'), 403, 'Payslips show salary amounts and need the sensitive payroll permission.');
        $run->loadMissing(['branch', 'period']);
        abort_unless($this->branchAccess->canAccess($actor, $run->branch), 403);
        abort_unless($this->isIssuable($run), 403, 'Payslips are issued only for approved, posted or paid payroll runs.');
        abort_if($only && $only->payroll_run_id !== $run->id, 404);

        $lines = $run->lines()->with(['employee.branch', 'items.component'])->when($only, fn ($query) => $query->whereKey($only->id))->get()
            ->sortBy(fn (PayrollRunLine $line): string => (string) $line->employee->employee_code)->values();

        $payslips = $lines->map(function (PayrollRunLine $line): array {
            $items = $line->items->sortBy(fn (PayrollRunLineItem $item): array => [$item->component->sort_order, $item->component->name])->values();
            $describe = fn (PayrollRunLineItem $item): array => ['name' => $item->component->name, 'amount' => AccountingDecimal::string(AccountingDecimal::of($item->amount))];
            $earnings = $items->filter(fn (PayrollRunLineItem $item): bool => $item->component->isEarning())->map($describe)->values()->all();
            $deductions = $items->reject(fn (PayrollRunLineItem $item): bool => $item->component->isEarning())->map($describe)->values()->all();
            $sum = fn (array $rows): string => AccountingDecimal::string(array_reduce($rows, fn (BigDecimal $total, array $row): BigDecimal => $total->plus(AccountingDecimal::of($row['amount'])), BigDecimal::zero()->toScale(4)));

            return [
                'line' => $line, 'earnings' => $earnings, 'deductions' => $deductions,
                'gross' => $sum($earnings), 'deductions_total' => $sum($deductions), 'net' => AccountingDecimal::string(AccountingDecimal::of($line->net_amount)),
            ];
        });

        return ['run' => $run, 'paymentStatus' => $this->paymentStatus($run), 'payslips' => $payslips];
    }

    /** Salary is paid per run through the payment workflow; payslips state where the run stands. */
    private function paymentStatus(PayrollRun $run): string
    {
        return match ($run->status) {
            PayrollRunStatus::Paid => 'Paid',
            PayrollRunStatus::Posted => AccountingDecimal::of($this->runs->paidAmount($run))->isPositive() ? 'Partly paid — salary payment in progress' : 'Accrued — awaiting salary payment',
            default => 'Approved — not yet posted or paid',
        };
    }
}
