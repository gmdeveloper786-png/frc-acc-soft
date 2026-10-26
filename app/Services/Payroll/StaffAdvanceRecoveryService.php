<?php

namespace App\Services\Payroll;

use App\Enums\PayrollRunStatus;
use App\Enums\StaffAdvanceStatus;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceInstalment;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads staff advance recovery from posted payroll runs. It never writes a
 * payroll line or a journal: a recovery exists only when an approved payroll
 * run carrying the recovery component for the employee has been posted.
 *
 * Allocation is recomputed from scratch, so reversing a payroll run releases
 * its recoveries again. For one employee and recovery component, each posted
 * deduction settles the oldest unrecovered instalments due in or before the
 * period of its run (oldest advance first); any excess is not carried forward.
 */
class StaffAdvanceRecoveryService
{
    /** Payroll runs whose accrual journal stands. */
    public const RECOVERING_RUN_STATUSES = [PayrollRunStatus::Posted, PayrollRunStatus::Paid];

    public function __construct(private AuditLogger $audit) {}

    /** Recompute recovery for every employee on a payroll run, e.g. after it was posted or reversed. */
    public function syncForRun(PayrollRun $run): void
    {
        $employeeIds = $run->lines()->pluck('employee_id')->all();
        StaffAdvance::query()->whereIn('employee_id', $employeeIds)->whereIn('status', [StaffAdvanceStatus::Active->value, StaffAdvanceStatus::Settled->value])
            ->select(['employee_id', 'payroll_component_id'])->distinct()->get()
            ->each(fn (StaffAdvance $pair) => $this->sync((int) $pair->employee_id, (int) $pair->payroll_component_id));
    }

    /** Recompute recovery for the employee and recovery component of this advance. */
    public function syncAdvance(StaffAdvance $advance): void
    {
        if ($advance->status->isDisbursed()) {
            $this->sync((int) $advance->employee_id, (int) $advance->payroll_component_id);
        }
    }

    /**
     * Allocate posted recovery deductions of one employee and component to the
     * instalments of their disbursed advances, and settle or reopen advances.
     */
    public function sync(int $employeeId, int $componentId): void
    {
        DB::transaction(function () use ($employeeId, $componentId): void {
            $advances = StaffAdvance::query()->where('employee_id', $employeeId)->where('payroll_component_id', $componentId)
                ->whereIn('status', [StaffAdvanceStatus::Active->value, StaffAdvanceStatus::Settled->value])
                ->orderBy('id')->lockForUpdate()->get();
            if ($advances->isEmpty()) {
                return;
            }
            $instalments = StaffAdvanceInstalment::query()->with('period:id,start_date')->whereIn('staff_advance_id', $advances->pluck('id'))->lockForUpdate()->get()
                ->sortBy(fn (StaffAdvanceInstalment $instalment): array => [$instalment->period->start_date->toDateString(), $instalment->staff_advance_id, $instalment->sequence])->values();

            $zero = BigDecimal::zero()->toScale(4);
            $allocated = $instalments->mapWithKeys(fn (StaffAdvanceInstalment $instalment): array => [$instalment->id => ['recovered' => $zero, 'line' => null]])->all();
            foreach ($this->postedDeductions($employeeId, $componentId) as $deduction) {
                $remaining = AccountingDecimal::of($deduction->amount);
                foreach ($instalments as $instalment) {
                    if (! $remaining->isPositive()) {
                        break;
                    }
                    if ($instalment->period->start_date->toDateString() > $deduction->period_start) {
                        continue;
                    }
                    $open = AccountingDecimal::of($instalment->amount)->minus($allocated[$instalment->id]['recovered']);
                    if (! $open->isPositive()) {
                        continue;
                    }
                    $take = $remaining->isLessThan($open) ? $remaining : $open;
                    $allocated[$instalment->id] = ['recovered' => $allocated[$instalment->id]['recovered']->plus($take), 'line' => (int) $deduction->line_id];
                    $remaining = $remaining->minus($take);
                }
            }

            foreach ($instalments as $instalment) {
                $recovered = AccountingDecimal::string($allocated[$instalment->id]['recovered']);
                if (! AccountingDecimal::of($instalment->recovered_amount)->isEqualTo($allocated[$instalment->id]['recovered']) || $instalment->payroll_run_line_id !== $allocated[$instalment->id]['line']) {
                    $instalment->update(['recovered_amount' => $recovered, 'payroll_run_line_id' => $allocated[$instalment->id]['line']]);
                }
            }

            foreach ($advances as $advance) {
                $advance->load('instalments');
                $settled = AccountingDecimal::of($advance->outstandingAmount())->isZero();
                $target = $settled ? StaffAdvanceStatus::Settled : StaffAdvanceStatus::Active;
                if ($target === $advance->status) {
                    continue;
                }
                $from = $advance->status;
                $advance->update(['status' => $target, 'settled_at' => $settled ? now() : null]);
                $advance->history()->create([
                    'action' => $settled ? 'settled' : 'reopened', 'from_status' => $from->value, 'to_status' => $target->value, 'actor_id' => null, 'created_at' => now(),
                    'comments' => $settled ? 'Fully recovered through posted payroll runs.' : 'A recovering payroll run is no longer posted.',
                ]);
                $this->audit->log($settled ? 'staff_advances.settled' : 'staff_advances.reopened', $advance, ['reference' => $advance->reference, 'recovered_amount' => $advance->recoveredAmount()]);
            }
        }, 3);
    }

    /**
     * Unrecovered instalments due in or before the period of a payroll run, per
     * employee of the run's branch and recovery component.
     *
     * @return Collection<int, array{employee: Employee, component_id: int, component_name: string, due: string, advances: list<string>}>
     */
    public function dueForRun(PayrollRun $run): Collection
    {
        $run->loadMissing('period');
        $advances = StaffAdvance::query()->with(['employee:id,employee_code,name,branch_id', 'component:id,name', 'instalments.period:id,start_date'])
            ->where('branch_id', $run->branch_id)->where('status', StaffAdvanceStatus::Active->value)->orderBy('id')->get();
        $zero = BigDecimal::zero()->toScale(4);
        $due = [];
        foreach ($advances as $advance) {
            $amount = $advance->instalments
                ->filter(fn (StaffAdvanceInstalment $instalment): bool => $instalment->period->start_date->lte($run->period->start_date))
                ->reduce(fn (BigDecimal $sum, StaffAdvanceInstalment $instalment): BigDecimal => $sum->plus(AccountingDecimal::of($instalment->outstandingAmount())), $zero);
            if (! $amount->isPositive()) {
                continue;
            }
            $key = $advance->employee_id.':'.$advance->payroll_component_id;
            $due[$key] ??= ['employee' => $advance->employee, 'component_id' => (int) $advance->payroll_component_id, 'component_name' => $advance->component->name, 'total' => $zero, 'advances' => []];
            $due[$key]['total'] = $due[$key]['total']->plus($amount);
            $due[$key]['advances'][] = (string) $advance->reference;
        }

        return collect($due)->map(fn (array $entry): array => [
            'employee' => $entry['employee'], 'component_id' => $entry['component_id'], 'component_name' => $entry['component_name'],
            'due' => AccountingDecimal::string($entry['total']), 'advances' => $entry['advances'],
        ])->values();
    }

    /**
     * Recovery component deductions on posted payroll runs, oldest period first.
     *
     * @return Collection<int, object{line_id: int, amount: string, period_start: string}>
     */
    /**
     * Whether any payroll run that is still alive (draft to paid) deducts this
     * component from the employee. Such a deduction may be recovering an
     * advance, so the advance's payout must not be undone underneath it.
     */
    public function hasRecoveryDeductions(int $employeeId, int $componentId): bool
    {
        return DB::table('payroll_run_line_items')
            ->join('payroll_run_lines', 'payroll_run_lines.id', '=', 'payroll_run_line_items.payroll_run_line_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.payroll_run_id')
            ->where('payroll_run_lines.employee_id', $employeeId)->where('payroll_run_line_items.payroll_component_id', $componentId)
            ->where('payroll_run_line_items.amount', '>', 0)
            ->whereNotIn('payroll_runs.status', [PayrollRunStatus::Rejected->value, PayrollRunStatus::Reversed->value])
            ->exists();
    }

    private function postedDeductions(int $employeeId, int $componentId): Collection
    {
        return DB::table('payroll_run_line_items')
            ->join('payroll_run_lines', 'payroll_run_lines.id', '=', 'payroll_run_line_items.payroll_run_line_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.payroll_run_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'payroll_runs.accounting_period_id')
            ->where('payroll_run_lines.employee_id', $employeeId)->where('payroll_run_line_items.payroll_component_id', $componentId)
            ->whereIn('payroll_runs.status', array_map(fn (PayrollRunStatus $status): string => $status->value, self::RECOVERING_RUN_STATUSES))
            ->orderBy('accounting_periods.start_date')->orderBy('payroll_runs.id')
            ->get(['payroll_run_lines.id as line_id', 'payroll_run_line_items.amount', 'accounting_periods.start_date as period_start'])
            ->map(function (object $row): object {
                $row->period_start = substr((string) $row->period_start, 0, 10);

                return $row;
            });
    }
}
