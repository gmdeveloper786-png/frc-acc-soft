<?php

namespace App\Services\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\EmploymentStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollRunStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunPayment;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountingDecimal;
use App\Services\AccountMappingService;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\OperationalTransactionService;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Payroll run workflow: Draft → Validated → Submitted → Approved → Posted → Paid.
 *
 * The only arithmetic performed here is gross = Σ earnings, deductions =
 * Σ deductions and net = gross − deductions. Tax, leave and statutory amounts
 * are always supplied by an authorised user or a reviewed import.
 */
class PayrollRunService
{
    public const POLICY_SETTING = 'payroll.calculation_policy_approved';

    public function __construct(
        private BranchAccess $branchAccess,
        private AppSettings $settings,
        private AuditLogger $audit,
        private AccountMappingService $mappings,
        private JournalPostingService $posting,
        private JournalReversalService $reversal,
        private OperationalTransactionService $transactions,
    ) {}

    /** Whether the Director-controlled payroll calculation policy has been approved. */
    public function policyApproved(): bool
    {
        return $this->settings->integer(self::POLICY_SETTING, 0) === 1;
    }

    /**
     * Draft run from the salary structures of the branch's active employees.
     *
     * @param  array{branch_id: int|string, accounting_period_id: int|string}  $data
     */
    public function createFromStructures(array $data, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.manage');

        return DB::transaction(function () use ($data, $actor): PayrollRun {
            [$branch, $period] = $this->guardHeader((int) $data['branch_id'], (int) $data['accounting_period_id'], $actor);
            $lines = [];
            $employees = Employee::query()->with('salaryItems')->where('branch_id', $branch->id)->where('employment_status', EmploymentStatus::Active->value)->orderBy('employee_code')->get();
            foreach ($employees as $employee) {
                $amounts = $employee->salaryItems->mapWithKeys(fn ($item): array => [$item->payroll_component_id => $item->amount])->all();
                if ($amounts !== []) {
                    $lines[$employee->id] = $amounts;
                }
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['branch_id' => 'No active employee of this branch has a salary structure. Add salary structures first or use the payroll import.']);
            }

            return $this->createDraft($branch, $period, 'manual', $lines, $actor);
        }, 3);
    }

    /**
     * Draft run from reviewed import rows. Called only by the import
     * confirmation, which has already validated every row.
     *
     * @param  array<int, array<int, string>>  $lines  employee id => [component id => amount]
     */
    public function createFromImport(Branch $branch, AccountingPeriod $period, array $lines, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.import');

        return DB::transaction(function () use ($branch, $period, $lines, $actor): PayrollRun {
            [$branch, $period] = $this->guardHeader($branch->id, $period->id, $actor);

            return $this->createDraft($branch, $period, 'import', $lines, $actor);
        }, 3);
    }

    /**
     * Replace the component amounts of one line of a draft run.
     *
     * @param  array<int|string, mixed>  $amounts  component id => amount (blank or zero removes the item)
     */
    public function updateLine(PayrollRunLine $line, array $amounts, User $actor): PayrollRunLine
    {
        $this->authorize($actor, 'payroll.manage');

        return DB::transaction(function () use ($line, $amounts, $actor): PayrollRunLine {
            $run = $this->lock($line->run, $actor);
            $this->assertEditable($run);
            $line = $run->lines()->whereKey($line->id)->firstOrFail();
            $this->writeItems($line, $amounts);
            $this->refreshTotals($run);
            $this->audit->log('payroll.line_updated', $run, ['payroll_run_line_id' => $line->id, 'employee_id' => $line->employee_id]);

            return $line->refresh();
        });
    }

    public function addLine(PayrollRun $run, int $employeeId, User $actor): PayrollRunLine
    {
        $this->authorize($actor, 'payroll.manage');

        return DB::transaction(function () use ($run, $employeeId, $actor): PayrollRunLine {
            $run = $this->lock($run, $actor);
            $this->assertEditable($run);
            $employee = Employee::query()->with('salaryItems')->whereKey($employeeId)->where('branch_id', $run->branch_id)->where('employment_status', EmploymentStatus::Active->value)->first();
            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee of this branch.']);
            }
            if ($run->lines()->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'This employee is already on the payroll run.']);
            }
            $line = $run->lines()->create(['employee_id' => $employee->id]);
            $this->writeItems($line, $employee->salaryItems->mapWithKeys(fn ($item): array => [$item->payroll_component_id => $item->amount])->all());
            $this->refreshTotals($run);
            $this->audit->log('payroll.line_added', $run, ['payroll_run_line_id' => $line->id, 'employee_id' => $employee->id]);

            return $line->refresh();
        });
    }

    public function removeLine(PayrollRunLine $line, User $actor): void
    {
        $this->authorize($actor, 'payroll.manage');

        DB::transaction(function () use ($line, $actor): void {
            $run = $this->lock($line->run, $actor);
            $this->assertEditable($run);
            $line = $run->lines()->whereKey($line->id)->firstOrFail();
            $employeeId = $line->employee_id;
            $line->items()->get()->each->delete();
            $line->delete();
            $this->refreshTotals($run);
            $this->audit->log('payroll.line_removed', $run, ['employee_id' => $employeeId]);
        });
    }

    /**
     * Recompute every line and the run totals. The run becomes Validated only
     * when no line carries an error; otherwise it stays a draft.
     */
    public function validate(PayrollRun $run, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.manage');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->lock($run, $actor);
            $this->assertEditable($run);
            $from = $run->status;
            if ($from === PayrollRunStatus::Rejected) {
                // A returned run re-occupies its branch and period.
                $this->assertNoActiveRun($run->branch_id, $run->accounting_period_id, $run->id);
            }
            $this->assertOpenPeriod($run->period);
            $lines = $run->lines()->with(['employee', 'items.component'])->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'A payroll run needs at least one employee line.']);
            }

            $duplicates = $lines->countBy('employee_id')->filter(fn (int $count): bool => $count > 1);
            $errorCount = 0;
            foreach ($lines as $line) {
                $computed = $this->computeLine($line, $run);
                if ($duplicates->has($line->employee_id)) {
                    $computed['errors'][] = 'This employee appears more than once on the run.';
                }
                $errorCount += count($computed['errors']);
                $line->update([...$computed['totals'], 'validation_errors' => $computed['errors'] ?: null]);
            }
            $totals = $this->sumLines($run);
            $status = $errorCount === 0 ? PayrollRunStatus::Validated : PayrollRunStatus::Draft;
            $this->activate($run, [
                ...$totals, 'status' => $status, 'rejection_reason' => null,
                'validated_by' => $errorCount === 0 ? $actor->id : null, 'validated_at' => $errorCount === 0 ? now() : null,
            ]);
            $this->record($run, $errorCount === 0 ? 'validated' : 'validation_failed', $from, $actor, $errorCount === 0 ? null : "{$errorCount} validation error(s) must be corrected.");
            $this->audit->log('payroll.validated', $run, ['status' => $status->value, 'error_count' => $errorCount, ...$totals]);

            return $run->refresh();
        });
    }

    /** Return a validated run to draft so its lines can be corrected. */
    public function revertToDraft(PayrollRun $run, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.manage');

        return $this->transition($run, $actor, [PayrollRunStatus::Validated], PayrollRunStatus::Draft, 'reopened', ['validated_by' => null, 'validated_at' => null]);
    }

    /** Abandon an unsubmitted run. It stays on record but frees its branch and period. */
    public function discard(PayrollRun $run, string $reason, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.manage');

        return $this->transition($run, $actor, [PayrollRunStatus::Draft, PayrollRunStatus::Validated], PayrollRunStatus::Rejected, 'discarded', ['active_key' => null, 'rejection_reason' => 'Discarded by preparer: '.$reason], $reason);
    }

    public function submit(PayrollRun $run, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.submit');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== PayrollRunStatus::Validated) {
                throw ValidationException::withMessages(['status' => 'Only a validated payroll run can be submitted for approval.']);
            }
            $this->assertIntegrity($run);
            $run->update(['status' => PayrollRunStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->record($run, 'submitted', PayrollRunStatus::Validated, $actor);
            $this->audit->log('payroll.submitted', $run, $this->totals($run));

            return $run;
        });
    }

    public function approve(PayrollRun $run, User $actor, ?string $comments = null): PayrollRun
    {
        $this->authorize($actor, 'payroll.approve');

        return DB::transaction(function () use ($run, $actor, $comments): PayrollRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== PayrollRunStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted payroll run can be approved.']);
            }
            $this->assertNotMaker($run, $actor);
            $this->assertIntegrity($run);
            $run->update(['status' => PayrollRunStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->record($run, 'approved', PayrollRunStatus::Submitted, $actor, $comments);
            $this->audit->log('payroll.approved', $run, $this->totals($run));

            return $run;
        });
    }

    public function reject(PayrollRun $run, string $reason, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.approve');

        return DB::transaction(function () use ($run, $reason, $actor): PayrollRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== PayrollRunStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted payroll run can be rejected.']);
            }
            $this->assertNotMaker($run, $actor);
            $run->update(['status' => PayrollRunStatus::Rejected, 'active_key' => null, 'rejection_reason' => $reason]);
            $this->record($run, 'rejected', PayrollRunStatus::Submitted, $actor, $reason);
            $this->audit->log('payroll.rejected', $run, ['reason' => $reason]);

            return $run;
        });
    }

    /** Whether the user may approve or reject this submitted run right now. */
    public function canApprove(PayrollRun $run, User $actor): bool
    {
        return $run->status === PayrollRunStatus::Submitted
            && $actor->is_active && $actor->can('payroll.approve')
            && ! in_array($actor->id, $this->makers($run), true)
            && $this->branchAccess->canAccess($actor, $run->branch);
    }

    /**
     * Post the salary accrual: debit salary expense for the gross, credit
     * Salary Payable for the net and each deduction ledger for its total.
     */
    public function post(PayrollRun $run, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.post', 'journals.post');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== PayrollRunStatus::Approved || $run->journal_entry_id !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted payroll run can be posted.']);
            }
            if (! $this->policyApproved()) {
                throw ValidationException::withMessages(['policy' => 'Payroll posting is blocked: the "Payroll Calculation Policy Approved" setting ('.self::POLICY_SETTING.') has not been approved by the Director.']);
            }
            $period = $this->assertOpenPeriod($run->period);
            $this->assertIntegrity($run);
            $entries = $this->accrualEntries($run);

            $voucher = VoucherType::query()->where('code', 'SV')->where('is_active', true)->firstOrFail();
            $journal = JournalEntry::query()->create([
                'voucher_type_id' => $voucher->id, 'transaction_date' => $period->end_date->toDateString(),
                'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
                'branch_id' => $run->branch_id, 'narration' => 'Payroll accrual '.$period->name.' - '.$run->branch->name,
                'reference_number' => 'PAYROLL-'.$run->id, 'source_system' => 'accounting', 'source_type' => 'payroll_run',
                'source_id' => (string) $run->id, 'source_key' => 'payroll_run:'.$run->id, 'status' => JournalStatus::Approved,
                'total_debit' => $run->gross_amount, 'total_credit' => $run->gross_amount,
                'created_by' => $run->created_by, 'submitted_by' => $run->submitted_by, 'approved_by' => $run->approved_by,
                'submitted_at' => $run->submitted_at, 'approved_at' => $run->approved_at,
            ]);
            foreach ($entries as $index => $entry) {
                $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $entry['account_id'], 'description' => $entry['description'], 'debit' => $entry['debit'], 'credit' => $entry['credit']]);
            }
            $journal = $this->posting->postSystem($journal, $actor);
            $run->update(['status' => PayrollRunStatus::Posted, 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->record($run, 'posted', PayrollRunStatus::Approved, $actor, 'Voucher '.$journal->voucher_number);
            $this->audit->log('payroll.posted', $run, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, ...$this->totals($run)]);

            return $run->refresh();
        }, 3);
    }

    /**
     * Raise a salary payment through the existing payment engine. The payment
     * debits Salary Payable, so salary expense is never recognised twice. Its
     * submission, approval, disbursement and posting follow the normal
     * payment workflow and are not performed here.
     *
     * @param  array{type: string, financial_account_id: int|string, amount: mixed, transaction_date: string, reference_number?: ?string}  $data
     */
    public function pay(PayrollRun $run, array $data, User $actor): OperationalTransaction
    {
        $this->authorize($actor, 'payroll.pay');

        return DB::transaction(function () use ($run, $data, $actor): OperationalTransaction {
            $run = $this->lock($run, $actor);
            $this->syncPaidStatus($run);
            $run->refresh();
            if ($run->status !== PayrollRunStatus::Posted) {
                throw ValidationException::withMessages(['status' => 'Salary can only be paid for a posted payroll run that is not yet fully paid.']);
            }
            if (! in_array($data['type'], [OperationalTransactionType::BankPayment->value, OperationalTransactionType::CashPayment->value], true)) {
                throw ValidationException::withMessages(['type' => 'Salary is paid by a bank payment or a cash payment.']);
            }
            try {
                $amount = AccountingDecimal::of($data['amount']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => 'Enter a valid amount with at most four decimals.']);
            }
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
            }
            // Locking read: a plain read could miss payments committed while this transaction waited for the run.
            $outstanding = AccountingDecimal::of($run->net_amount)->minus($this->sumPayments($run, false, true));
            if ($amount->isGreaterThan($outstanding)) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the unpaid net salary of this run ('.AccountingDecimal::string($outstanding).' remaining after payments already raised).']);
            }

            $label = 'Salaries '.$run->period->name.' - '.$run->branch->name;
            $transaction = $this->transactions->create([
                'type' => $data['type'], 'branch_id' => $run->branch_id, 'transaction_date' => $data['transaction_date'],
                'amount' => AccountingDecimal::string($amount), 'financial_account_id' => (int) $data['financial_account_id'],
                'contra_account_id' => $this->mappings->resolve(AccountMappingKey::SalaryPayable)->id,
                'party_name' => $label, 'narration' => 'Salary payment for payroll run #'.$run->id.' ('.$label.')',
                'reference_number' => $data['reference_number'] ?? null, 'source_reference' => 'payroll_run:'.$run->id,
            ], $actor);
            PayrollRunPayment::query()->create(['payroll_run_id' => $run->id, 'operational_transaction_id' => $transaction->id, 'created_by' => $actor->id]);
            $this->record($run, 'payment_raised', $run->status, $actor, 'Payment #'.$transaction->id.' raised in the payment workflow.');
            $this->audit->log('payroll.payment_raised', $run, ['operational_transaction_id' => $transaction->id, 'type' => $transaction->type->value, 'amount' => $transaction->amount]);

            return $transaction;
        }, 3);
    }

    /** Net salary not yet covered by a linked payment that is still alive. */
    public function outstandingAmount(PayrollRun $run): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($run->net_amount)->minus($this->sumPayments($run, false)));
    }

    /** Net salary actually settled by posted payments. */
    public function paidAmount(PayrollRun $run): string
    {
        return AccountingDecimal::string($this->sumPayments($run, true));
    }

    /**
     * Mark the run Paid when its linked posted payments equal the net amount,
     * and back to Posted if a payment was reversed afterwards.
     */
    public function syncPaidStatus(PayrollRun $run): PayrollRun
    {
        if (! in_array($run->status, [PayrollRunStatus::Posted, PayrollRunStatus::Paid], true)) {
            return $run;
        }

        return DB::transaction(function () use ($run): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, [PayrollRunStatus::Posted, PayrollRunStatus::Paid], true)) {
                return $run;
            }
            $settled = $this->sumPayments($run, true)->isEqualTo(AccountingDecimal::of($run->net_amount));
            $target = $settled ? PayrollRunStatus::Paid : PayrollRunStatus::Posted;
            if ($target !== $run->status) {
                $from = $run->status;
                $run->update(['status' => $target, 'paid_at' => $settled ? now() : null]);
                $this->record($run, $settled ? 'paid' : 'payment_reopened', $from, null, $settled ? 'Posted salary payments equal the net amount.' : 'A salary payment is no longer posted.');
                $this->audit->log($settled ? 'payroll.paid' : 'payroll.payment_reopened', $run, ['paid_amount' => $this->paidAmount($run), 'net_amount' => $run->net_amount]);
            }

            return $run;
        });
    }

    /** Reverse the accrual of a posted run that has no live salary payment. */
    public function reverse(PayrollRun $run, string $date, string $reason, User $actor): PayrollRun
    {
        $this->authorize($actor, 'payroll.post');

        return DB::transaction(function () use ($run, $date, $reason, $actor): PayrollRun {
            $run = $this->lock($run, $actor);
            $this->syncPaidStatus($run);
            $run->refresh();
            if ($run->status !== PayrollRunStatus::Posted || ! $run->journalEntry) {
                throw ValidationException::withMessages(['status' => 'Only a posted, unpaid payroll run can be reversed.']);
            }
            if ($this->livePayments($run, true)->isNotEmpty()) {
                throw ValidationException::withMessages(['status' => 'This payroll run has linked salary payments. Reject or reverse them in the payment workflow first.']);
            }
            $period = AccountingPeriod::query()->with('fiscalYear')->where('status', AccountingPeriodStatus::Open->value)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
            if (! $period || $period->fiscalYear->status !== FiscalYearStatus::Open) {
                throw ValidationException::withMessages(['date' => 'No open accounting period exists for the reversal date.']);
            }
            $reversal = $this->reversal->reverse($run->journalEntry, $period, $date, $reason, $actor);
            $run->update(['status' => PayrollRunStatus::Reversed, 'active_key' => null, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->record($run, 'reversed', PayrollRunStatus::Posted, $actor, $reason);
            $this->audit->log('payroll.reversed', $run, ['reversal_journal_id' => $reversal->id, 'reason' => $reason]);

            return $run->refresh();
        }, 3);
    }

    /**
     * @param  array<int, array<int, mixed>>  $lines  employee id => [component id => amount]
     */
    private function createDraft(Branch $branch, AccountingPeriod $period, string $source, array $lines, User $actor): PayrollRun
    {
        $this->assertOpenPeriod($period);
        $this->assertNoActiveRun($branch->id, $period->id);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A payroll run needs at least one employee line.']);
        }
        try {
            $run = PayrollRun::query()->create([
                'branch_id' => $branch->id, 'accounting_period_id' => $period->id, 'status' => PayrollRunStatus::Draft,
                'active_key' => '1', 'source' => $source, 'created_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['accounting_period_id' => 'A payroll run already exists for this branch and period.']);
        }
        foreach ($lines as $employeeId => $amounts) {
            $line = $run->lines()->create(['employee_id' => $employeeId]);
            $this->writeItems($line, $amounts);
        }
        $this->refreshTotals($run);
        $this->record($run, 'created', null, $actor, $source === 'import' ? 'Created from a confirmed payroll import.' : 'Created from employee salary structures.');
        $this->audit->log('payroll.created', $run, ['branch_id' => $branch->id, 'accounting_period_id' => $period->id, 'source' => $source, 'line_count' => count($lines), ...$this->totals($run)]);

        return $run->refresh();
    }

    /**
     * Store the amounts of one line and recompute its totals.
     *
     * @param  array<int|string, mixed>  $amounts
     */
    private function writeItems(PayrollRunLine $line, array $amounts): void
    {
        $components = PayrollComponent::query()->whereKey(array_keys($amounts))->get()->keyBy('id');
        foreach ($amounts as $componentId => $value) {
            $component = $components->get((int) $componentId);
            if (! $component) {
                throw ValidationException::withMessages(["amounts.{$componentId}" => 'The selected payroll component does not exist.']);
            }
            try {
                $amount = AccountingDecimal::of(blank($value) ? '0' : $value);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name}: enter a valid amount with at most four decimals."]);
            }
            if ($amount->isNegative()) {
                throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name}: amounts cannot be negative."]);
            }
            $existing = $line->items()->where('payroll_component_id', $component->id)->first();
            if ($amount->isZero()) {
                $existing?->delete();

                continue;
            }
            if (! $component->is_active && ! $existing) {
                throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name} is inactive and cannot be added."]);
            }
            $line->items()->updateOrCreate(['payroll_component_id' => $component->id], ['amount' => AccountingDecimal::string($amount)]);
        }
        $line->load(['employee', 'items.component']);
        $line->update([...$this->computeLine($line, $line->run)['totals'], 'validation_errors' => null]);
    }

    /**
     * @return array{totals: array{gross_amount: string, total_deductions: string, net_amount: string}, errors: list<string>}
     */
    private function computeLine(PayrollRunLine $line, PayrollRun $run): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        [$gross, $deductions, $errors] = [$zero, $zero, []];
        foreach ($line->items as $item) {
            $amount = AccountingDecimal::of($item->amount);
            if ($amount->isNegative()) {
                $errors[] = "{$item->component->name} has a negative amount.";
            }
            if (! $item->component->is_active) {
                $errors[] = "{$item->component->name} is an inactive component.";
            }
            if ($item->component->isEarning()) {
                $gross = $gross->plus($amount);
            } else {
                $deductions = $deductions->plus($amount);
            }
        }
        $net = $gross->minus($deductions);
        if ($net->isNegative()) {
            $errors[] = 'Net pay is negative: deductions exceed earnings.';
        }
        if ($line->employee->employment_status !== EmploymentStatus::Active) {
            $errors[] = 'The employee is not active.';
        }
        if ($line->employee->branch_id !== $run->branch_id) {
            $errors[] = 'The employee does not belong to the branch of this run.';
        }

        return ['totals' => ['gross_amount' => AccountingDecimal::string($gross), 'total_deductions' => AccountingDecimal::string($deductions), 'net_amount' => AccountingDecimal::string($net)], 'errors' => $errors];
    }

    /** @return array{gross_amount: string, total_deductions: string, net_amount: string} */
    private function sumLines(PayrollRun $run): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        [$gross, $deductions] = [$zero, $zero];
        foreach ($run->lines()->get(['gross_amount', 'total_deductions']) as $line) {
            $gross = $gross->plus(AccountingDecimal::of($line->gross_amount));
            $deductions = $deductions->plus(AccountingDecimal::of($line->total_deductions));
        }

        return ['gross_amount' => AccountingDecimal::string($gross), 'total_deductions' => AccountingDecimal::string($deductions), 'net_amount' => AccountingDecimal::string($gross->minus($deductions))];
    }

    private function refreshTotals(PayrollRun $run): void
    {
        $run->update($this->sumLines($run));
    }

    /** Stored totals must still equal the stored items, with no negative pay. */
    private function assertIntegrity(PayrollRun $run): void
    {
        $lines = $run->lines()->with(['employee', 'items.component'])->get();
        $zero = BigDecimal::zero()->toScale(4);
        [$gross, $deductions] = [$zero, $zero];
        foreach ($lines as $line) {
            $computed = $this->computeLine($line, $run);
            if ($computed['errors'] !== [] || ! AccountingDecimal::of($computed['totals']['net_amount'])->isEqualTo(AccountingDecimal::of($line->net_amount))) {
                throw ValidationException::withMessages(['status' => 'Payroll lines no longer pass validation. Return the run to draft and validate it again.']);
            }
            $gross = $gross->plus(AccountingDecimal::of($computed['totals']['gross_amount']));
            $deductions = $deductions->plus(AccountingDecimal::of($computed['totals']['total_deductions']));
        }
        $matches = $gross->isEqualTo(AccountingDecimal::of($run->gross_amount))
            && $deductions->isEqualTo(AccountingDecimal::of($run->total_deductions))
            && $gross->minus($deductions)->isEqualTo(AccountingDecimal::of($run->net_amount));
        if ($lines->isEmpty() || ! $gross->isPositive() || ! $matches) {
            throw ValidationException::withMessages(['status' => 'Payroll totals do not match the payroll lines. Return the run to draft and validate it again.']);
        }
    }

    /**
     * Journal lines of the accrual, already balanced: Σ debit = gross = net + Σ deductions.
     *
     * @return list<array{account_id: int, description: string, debit: string, credit: string}>
     */
    private function accrualEntries(PayrollRun $run): array
    {
        $salaryExpense = $this->mappings->resolve(AccountMappingKey::SalaryExpense);
        $salaryPayable = $this->mappings->resolve(AccountMappingKey::SalaryPayable);
        $zero = BigDecimal::zero()->toScale(4);

        /** @var array<int, array{component: PayrollComponent, total: BigDecimal}> $byComponent */
        $byComponent = [];
        foreach ($run->lines()->with('items.component.account')->get() as $line) {
            foreach ($line->items as $item) {
                $byComponent[$item->payroll_component_id] ??= ['component' => $item->component, 'total' => $zero];
                $byComponent[$item->payroll_component_id]['total'] = $byComponent[$item->payroll_component_id]['total']->plus(AccountingDecimal::of($item->amount));
            }
        }

        $debits = [];
        $credits = [];
        foreach ($byComponent as $entry) {
            ['component' => $component, 'total' => $total] = $entry;
            if ($total->isZero()) {
                continue;
            }
            if ($component->isEarning()) {
                $account = $component->account_id ? $component->account : $salaryExpense;
                if (! $this->mappings->isUsable($account, AccountType::Expense)) {
                    throw ValidationException::withMessages(['mapping' => "Configuration error: the expense ledger of payroll component {$component->name} is not an active, postable expense account."]);
                }
                $debits[$account->id] ??= ['account' => $account, 'total' => $zero];
                $debits[$account->id]['total'] = $debits[$account->id]['total']->plus($total);

                continue;
            }
            if (! $this->isPostableLedger($component->account)) {
                throw ValidationException::withMessages(['mapping' => "Configuration error: deduction component {$component->name} has no active, postable ledger. Set its account under Payroll Components before posting."]);
            }
            $credits[] = ['account_id' => $component->account->id, 'description' => $component->name.' withheld', 'debit' => '0.0000', 'credit' => AccountingDecimal::string($total)];
        }

        $entries = [];
        foreach ($debits as $debit) {
            $entries[] = ['account_id' => $debit['account']->id, 'description' => 'Salaries and benefits', 'debit' => AccountingDecimal::string($debit['total']), 'credit' => '0.0000'];
        }
        if (AccountingDecimal::of($run->net_amount)->isPositive()) {
            $entries[] = ['account_id' => $salaryPayable->id, 'description' => 'Net salaries payable', 'debit' => '0.0000', 'credit' => AccountingDecimal::string(AccountingDecimal::of($run->net_amount))];
        }

        return [...$entries, ...$credits];
    }

    private function isPostableLedger(?Account $account): bool
    {
        return $account !== null && $account->is_active && $account->is_postable && $account->level === AccountLevel::Ledger;
    }

    /** Linked payments that still reserve part of the net salary. */
    private function livePayments(PayrollRun $run, bool $lock = false): Collection
    {
        $payments = $run->payments()->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $transactions = OperationalTransaction::query()->whereKey($payments->pluck('operational_transaction_id'))->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $payments->each(fn (PayrollRunPayment $payment) => $payment->setRelation('transaction', $transactions->get($payment->operational_transaction_id)));

        return $payments
            ->filter(fn (PayrollRunPayment $payment): bool => ! in_array($payment->transaction->status, [OperationalTransactionStatus::Rejected, OperationalTransactionStatus::Reversed], true))
            ->values();
    }

    private function sumPayments(PayrollRun $run, bool $postedOnly, bool $lock = false): BigDecimal
    {
        return $this->livePayments($run, $lock)
            ->filter(fn (PayrollRunPayment $payment): bool => ! $postedOnly || $payment->transaction->status === OperationalTransactionStatus::Posted)
            ->reduce(fn (BigDecimal $sum, PayrollRunPayment $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->transaction->amount)), BigDecimal::zero()->toScale(4));
    }

    /** @return array{0: Branch, 1: AccountingPeriod} */
    private function guardHeader(int $branchId, int $periodId, User $actor): array
    {
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

        return [$branch, AccountingPeriod::query()->with('fiscalYear')->findOrFail($periodId)];
    }

    private function assertOpenPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $period->loadMissing('fiscalYear');
        if ($period->status !== AccountingPeriodStatus::Open || $period->fiscalYear->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['accounting_period_id' => 'The accounting period and its fiscal year must both be open.']);
        }

        return $period;
    }

    private function assertNoActiveRun(int $branchId, int $periodId, ?int $exceptRunId = null): void
    {
        $exists = PayrollRun::query()->where('branch_id', $branchId)->where('accounting_period_id', $periodId)->whereNotNull('active_key')
            ->when($exceptRunId, fn ($query) => $query->whereKeyNot($exceptRunId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['accounting_period_id' => 'A payroll run already exists for this branch and period.']);
        }
    }

    private function assertEditable(PayrollRun $run): void
    {
        if (! $run->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Payroll lines can only change while the run is a draft or was returned for correction.']);
        }
    }

    private function assertNotMaker(PayrollRun $run, User $actor): void
    {
        if (in_array($actor->id, $this->makers($run), true)) {
            throw ValidationException::withMessages(['approval' => 'The user who created, validated or submitted a payroll run cannot approve or reject it.']);
        }
    }

    /** @return list<int> */
    private function makers(PayrollRun $run): array
    {
        return array_values(array_filter([$run->created_by, $run->validated_by, $run->submitted_by]));
    }

    /**
     * Re-occupy the branch and period, turning a lost race into a clear message.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function activate(PayrollRun $run, array $attributes): void
    {
        try {
            $run->update([...$attributes, 'active_key' => '1']);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['accounting_period_id' => 'A payroll run already exists for this branch and period.']);
        }
    }

    /**
     * @param  list<PayrollRunStatus>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(PayrollRun $run, User $actor, array $from, PayrollRunStatus $to, string $action, array $changes = [], ?string $comments = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor, $from, $to, $action, $changes, $comments): PayrollRun {
            $run = $this->lock($run, $actor);
            if (! in_array($run->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'The payroll run is not eligible for this step.']);
            }
            $previous = $run->status;
            $run->update([...$changes, 'status' => $to]);
            $this->record($run, $action, $previous, $actor, $comments);
            $this->audit->log('payroll.'.$action, $run, ['from_status' => $previous->value, 'to_status' => $to->value]);

            return $run;
        });
    }

    private function lock(PayrollRun $run, User $actor): PayrollRun
    {
        $run = PayrollRun::query()->with(['branch', 'period.fiscalYear'])->lockForUpdate()->findOrFail($run->id);
        abort_unless($this->branchAccess->canAccess($actor, $run->branch), 403);

        return $run;
    }

    private function authorize(User $actor, string ...$permissions): void
    {
        abort_unless($actor->is_active, 403);
        foreach ($permissions as $permission) {
            abort_unless($actor->can($permission), 403);
        }
    }

    /** @return array{gross_amount: string, total_deductions: string, net_amount: string} */
    private function totals(PayrollRun $run): array
    {
        return ['gross_amount' => (string) $run->gross_amount, 'total_deductions' => (string) $run->total_deductions, 'net_amount' => (string) $run->net_amount];
    }

    private function record(PayrollRun $run, string $action, ?PayrollRunStatus $from, ?User $actor, ?string $comments = null): void
    {
        $run->history()->create([
            'action' => $action, 'from_status' => $from?->value, 'to_status' => $run->status->value,
            'totals' => $this->totals($run), 'actor_id' => $actor?->id, 'comments' => $comments, 'created_at' => now(),
        ]);
    }
}
