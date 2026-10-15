<?php

namespace App\Services\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\EmploymentStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollComponentType;
use App\Enums\StaffAdvanceStatus;
use App\Enums\StaffAdvanceType;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\PayrollRunPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\ManualJournalGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff advances and loans: Draft → Submitted → Approved → Active → Settled,
 * or Cancelled before disbursement.
 *
 * This is a register and a recovery schedule only. Advance and loan rules are
 * not approved by the client, so nothing here posts a journal or creates a
 * payment: the payout is an existing posted cash or bank payment that is
 * linked to the advance, and recovery is read from posted payroll runs, whose
 * posting stays under the payroll calculation policy gate.
 */
class StaffAdvanceService
{
    /** Longest recovery schedule accepted. */
    public const MAX_INSTALMENTS = 120;

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
        private ManualJournalGuard $guard,
        private StaffAdvanceRecoveryService $recovery,
        private PayrollRunService $payrollRuns,
    ) {}

    /**
     * Deduction components that may recover an advance: active, with an active
     * postable asset or liability ledger that is not a cash, bank or petty cash ledger.
     *
     * @return Collection<int, PayrollComponent>
     */
    public function eligibleComponents(): Collection
    {
        $controlled = $this->guard->controlledAccountIds();

        return PayrollComponent::query()->with('account')->where('type', PayrollComponentType::Deduction->value)->where('is_active', true)
            ->whereNotNull('account_id')->whereNotIn('account_id', $controlled)
            ->whereHas('account', fn (Builder $query) => $query->where('is_active', true)->where('is_postable', true)->where('level', AccountLevel::Ledger->value)
                ->whereIn('account_type', [AccountType::Asset->value, AccountType::Liability->value]))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.manage', 'payroll.view_sensitive');

        return DB::transaction(function () use ($data, $actor): StaffAdvance {
            [$attributes, $schedule] = $this->prepare($data, $actor);
            $advance = StaffAdvance::query()->create([...$attributes, 'status' => StaffAdvanceStatus::Draft, 'created_by' => $actor->id]);
            $advance->update(['reference' => $advance->type->referencePrefix().'-'.str_pad((string) $advance->id, 6, '0', STR_PAD_LEFT)]);
            $this->writeSchedule($advance, $schedule);
            $this->record($advance, 'created', null, $actor);
            $this->audit->log('staff_advances.created', $advance, ['reference' => $advance->reference, 'employee_id' => $advance->employee_id, 'type' => $advance->type->value, 'principal_amount' => (string) $advance->principal_amount, 'instalments' => count($schedule)], $actor);

            return $advance->refresh();
        }, 3);
    }

    /**
     * Change the terms of a draft and regenerate its schedule.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(StaffAdvance $advance, array $data, User $actor): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.manage', 'payroll.view_sensitive');

        return DB::transaction(function () use ($advance, $data, $actor): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            if (! $advance->status->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft staff advance can be edited.']);
            }
            [$attributes, $schedule] = $this->prepare($data, $actor);
            $advance->update($attributes);
            $advance->instalments()->delete();
            $this->writeSchedule($advance, $schedule);
            $this->audit->log('staff_advances.updated', $advance, ['reference' => $advance->reference, 'principal_amount' => (string) $advance->principal_amount, 'instalments' => count($schedule)], $actor);

            return $advance->refresh();
        }, 3);
    }

    public function submit(StaffAdvance $advance, User $actor): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.manage');

        return DB::transaction(function () use ($advance, $actor): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            if ($advance->status !== StaffAdvanceStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft staff advance can be submitted for approval.']);
            }
            $this->assertSchedule($advance);
            $advance->update(['status' => StaffAdvanceStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->record($advance, 'submitted', StaffAdvanceStatus::Draft, $actor);
            $this->audit->log('staff_advances.submitted', $advance, ['reference' => $advance->reference], $actor);

            return $advance;
        });
    }

    public function approve(StaffAdvance $advance, User $actor, ?string $comments = null): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.approve', 'payroll.view_sensitive');

        return DB::transaction(function () use ($advance, $actor, $comments): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            if ($advance->status !== StaffAdvanceStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted staff advance can be approved.']);
            }
            $this->assertNotMaker($advance, $actor);
            $this->recoveryComponent((int) $advance->payroll_component_id);
            $this->assertSchedule($advance);
            $advance->update(['status' => StaffAdvanceStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->record($advance, 'approved', StaffAdvanceStatus::Submitted, $actor, $comments);
            $this->audit->log('staff_advances.approved', $advance, ['reference' => $advance->reference, 'principal_amount' => (string) $advance->principal_amount], $actor);

            return $advance;
        });
    }

    /** Return a submitted advance to its preparer as a draft. */
    public function reject(StaffAdvance $advance, string $reason, User $actor): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.approve');

        return DB::transaction(function () use ($advance, $reason, $actor): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            if ($advance->status !== StaffAdvanceStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted staff advance can be returned.']);
            }
            $this->assertNotMaker($advance, $actor);
            $advance->update(['status' => StaffAdvanceStatus::Draft, 'submitted_by' => null, 'submitted_at' => null, 'rejection_reason' => $reason]);
            $this->record($advance, 'rejected', StaffAdvanceStatus::Submitted, $actor, $reason);
            $this->audit->log('staff_advances.rejected', $advance, ['reference' => $advance->reference, 'reason' => $reason], $actor);

            return $advance;
        });
    }

    /** Cancel an advance that has not been paid out. */
    public function cancel(StaffAdvance $advance, string $reason, User $actor): StaffAdvance
    {
        abort_unless($actor->is_active && ($actor->can('staff_advances.manage') || $actor->can('staff_advances.approve')), 403);

        return DB::transaction(function () use ($advance, $reason, $actor): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            $cancellable = [StaffAdvanceStatus::Draft, StaffAdvanceStatus::Submitted, StaffAdvanceStatus::Approved];
            if (! in_array($advance->status, $cancellable, true) || $advance->disbursement_transaction_id !== null) {
                throw ValidationException::withMessages(['status' => 'Only a staff advance that has not been disbursed can be cancelled.']);
            }
            $from = $advance->status;
            $advance->update(['status' => StaffAdvanceStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->record($advance, 'cancelled', $from, $actor, $reason);
            $this->audit->log('staff_advances.cancelled', $advance, ['reference' => $advance->reference, 'reason' => $reason], $actor);

            return $advance;
        });
    }

    /**
     * Posted cash or bank payments that may be the payout of this approved
     * advance: same branch, same amount, debiting the recovery component's
     * ledger, and not already linked to an advance or a payroll run.
     *
     * @return Collection<int, OperationalTransaction>
     */
    public function eligibleDisbursements(StaffAdvance $advance): Collection
    {
        $advance->loadMissing('component');
        if ($advance->status !== StaffAdvanceStatus::Approved || ! $advance->component?->account_id) {
            return new Collection;
        }

        return OperationalTransaction::query()->with('financialAccount')
            ->whereIn('type', [OperationalTransactionType::CashPayment->value, OperationalTransactionType::BankPayment->value])
            ->where('status', OperationalTransactionStatus::Posted->value)->where('branch_id', $advance->branch_id)
            ->where('amount', AccountingDecimal::string(AccountingDecimal::of($advance->principal_amount)))
            ->where('contra_account_id', $advance->component->account_id)
            ->whereNotIn('id', StaffAdvance::query()->whereNotNull('disbursement_transaction_id')->select('disbursement_transaction_id'))
            ->whereNotIn('id', PayrollRunPayment::query()->select('operational_transaction_id'))
            ->orderByDesc('transaction_date')->orderByDesc('id')->limit(100)->get();
    }

    /** Record the posted payment that paid the advance out. The advance becomes Active. */
    public function linkDisbursement(StaffAdvance $advance, int $transactionId, User $actor): StaffAdvance
    {
        $this->authorize($actor, 'staff_advances.manage', 'payroll.view_sensitive');

        $advance = DB::transaction(function () use ($advance, $transactionId, $actor): StaffAdvance {
            $advance = $this->lock($advance, $actor);
            if ($advance->status !== StaffAdvanceStatus::Approved || $advance->disbursement_transaction_id !== null) {
                throw ValidationException::withMessages(['status' => 'A disbursement can only be linked to an approved advance that has none yet.']);
            }
            $component = $this->recoveryComponent((int) $advance->payroll_component_id);
            $transaction = OperationalTransaction::query()->lockForUpdate()->find($transactionId);
            if (! $transaction || ! $this->eligibleDisbursements($advance)->contains('id', $transaction->id)) {
                throw ValidationException::withMessages(['operational_transaction_id' => 'Select a posted cash or bank payment of this branch for exactly '.number_format((float) $advance->principal_amount, 2).' that debits '.$component->account->name.' and is not linked elsewhere.']);
            }
            $advance->update(['disbursement_transaction_id' => $transaction->id, 'disbursement_linked_by' => $actor->id, 'disbursed_at' => $transaction->posted_at ?? now(), 'status' => StaffAdvanceStatus::Active]);
            $this->record($advance, 'disbursed', StaffAdvanceStatus::Approved, $actor, 'Payment #'.$transaction->id.' ('.$transaction->type->label().') linked as the payout.');
            $this->audit->log('staff_advances.disbursement_linked', $advance, ['reference' => $advance->reference, 'operational_transaction_id' => $transaction->id, 'amount' => (string) $transaction->amount], $actor);

            return $advance;
        }, 3);
        $this->recovery->syncAdvance($advance);

        return $advance->refresh();
    }

    /** Whether the user may approve or return this submitted advance right now. */
    public function canApprove(StaffAdvance $advance, User $actor): bool
    {
        return $advance->status === StaffAdvanceStatus::Submitted
            && $actor->is_active && $actor->can('staff_advances.approve') && $actor->can('payroll.view_sensitive')
            && ! in_array($actor->id, $this->makers($advance), true)
            && $this->branchAccess->canAccess($actor, $advance->branch);
    }

    /**
     * Put the due recovery of each employee on a draft payroll run, through the
     * ordinary line editing of the payroll run workflow. The preparer triggers
     * it; the run must still be validated, submitted, approved and posted.
     *
     * @return int Number of lines changed.
     */
    public function applyDueRecoveries(PayrollRun $run, User $actor): int
    {
        $this->authorize($actor, 'payroll.manage', 'payroll.view_sensitive');
        abort_unless($this->branchAccess->canAccess($actor, $run->branch), 403);
        if (! $run->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Due recoveries can only be added while the payroll run is a draft.']);
        }
        $lines = $run->lines()->with('items')->get()->keyBy('employee_id');
        $changed = 0;
        foreach ($this->recovery->dueForRun($run) as $due) {
            $line = $lines->get($due['employee']->id);
            if (! $line) {
                continue;
            }
            $current = $line->items->firstWhere('payroll_component_id', $due['component_id']);
            if ($current && AccountingDecimal::of($current->amount)->isEqualTo(AccountingDecimal::of($due['due']))) {
                continue;
            }
            $this->payrollRuns->updateLine($line, [$due['component_id'] => $due['due']], $actor);
            $changed++;
        }

        return $changed;
    }

    /**
     * Split the principal into instalments. With a count, every instalment is
     * the principal divided by the count, cut to two decimals; with an amount,
     * every instalment is that amount. The last instalment absorbs the
     * remainder, so the schedule always sums exactly to the principal.
     *
     * @return list<string>
     */
    public function instalmentAmounts(string $principal, ?int $count, ?string $instalmentAmount): array
    {
        $total = AccountingDecimal::of($principal);
        if (! $total->isPositive()) {
            throw ValidationException::withMessages(['principal_amount' => 'The principal must be greater than zero.']);
        }
        if (($count === null) === ($instalmentAmount === null)) {
            throw ValidationException::withMessages(['instalment_count' => 'Enter either the number of instalments or the instalment amount.']);
        }
        if ($count !== null) {
            if ($count < 1 || $count > self::MAX_INSTALMENTS) {
                throw ValidationException::withMessages(['instalment_count' => 'The number of instalments must be between 1 and '.self::MAX_INSTALMENTS.'.']);
            }
            $each = $total->dividedBy($count, 2, RoundingMode::Down)->toScale(4);
            if (! $each->isPositive()) {
                throw ValidationException::withMessages(['instalment_count' => 'The principal is too small for this many instalments.']);
            }
        } else {
            $each = AccountingDecimal::of($instalmentAmount);
            if (! $each->isPositive() || $each->isGreaterThan($total)) {
                throw ValidationException::withMessages(['instalment_amount' => 'The instalment amount must be greater than zero and not more than the principal.']);
            }
            $count = $total->dividedBy($each, 0, RoundingMode::Up)->toInt();
            if ($count > self::MAX_INSTALMENTS) {
                throw ValidationException::withMessages(['instalment_amount' => 'This instalment amount needs more than '.self::MAX_INSTALMENTS.' instalments.']);
            }
        }
        $amounts = array_fill(0, $count - 1, AccountingDecimal::string($each));
        $amounts[] = AccountingDecimal::string($total->minus($each->multipliedBy($count - 1)));

        return $amounts;
    }

    /**
     * Validate the input and build the attributes and schedule of an advance.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<array{period: AccountingPeriod, amount: string}>}
     */
    private function prepare(array $data, User $actor): array
    {
        $employee = Employee::query()->with('branch')->find((int) ($data['employee_id'] ?? 0));
        if (! $employee || $employee->employment_status !== EmploymentStatus::Active) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active employee.']);
        }
        abort_unless($this->branchAccess->canAccess($actor, $employee->branch), 403);
        $type = StaffAdvanceType::tryFrom((string) ($data['type'] ?? '')) ?? throw ValidationException::withMessages(['type' => 'Select advance or loan.']);
        $component = $this->recoveryComponent((int) ($data['payroll_component_id'] ?? 0));

        try {
            $principal = AccountingDecimal::string(AccountingDecimal::of($data['principal_amount'] ?? null));
            $instalmentAmount = filled($data['instalment_amount'] ?? null) ? AccountingDecimal::string(AccountingDecimal::of($data['instalment_amount'])) : null;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['principal_amount' => 'Enter valid amounts with at most four decimals.']);
        }
        $count = filled($data['instalment_count'] ?? null) ? (int) $data['instalment_count'] : null;
        $amounts = $this->instalmentAmounts($principal, $count, $instalmentAmount);

        $first = AccountingPeriod::query()->find((int) ($data['first_recovery_period_id'] ?? 0));
        if (! $first || $first->status !== AccountingPeriodStatus::Open) {
            throw ValidationException::withMessages(['first_recovery_period_id' => 'The first recovery period must be an open accounting period.']);
        }
        $periods = AccountingPeriod::query()->whereDate('start_date', '>=', $first->start_date->toDateString())->orderBy('start_date')->limit(count($amounts))->get();
        if ($periods->count() < count($amounts)) {
            throw ValidationException::withMessages(['first_recovery_period_id' => 'The schedule needs '.count($amounts).' accounting periods from '.$first->name.', but only '.$periods->count().' exist. Create the later accounting periods first or shorten the schedule.']);
        }
        if ($periods->contains(fn (AccountingPeriod $period): bool => $period->status === AccountingPeriodStatus::Closed)) {
            throw ValidationException::withMessages(['first_recovery_period_id' => 'The schedule would fall in a closed accounting period.']);
        }

        $attributes = [
            'employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'type' => $type, 'principal_amount' => $principal,
            'instalment_count' => $count, 'instalment_amount' => $instalmentAmount, 'first_recovery_period_id' => $first->id,
            'payroll_component_id' => $component->id, 'purpose' => trim((string) ($data['purpose'] ?? '')), 'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
        if ($attributes['purpose'] === '') {
            throw ValidationException::withMessages(['purpose' => 'Enter the purpose of the advance.']);
        }

        return [$attributes, array_map(fn (string $amount, int $index): array => ['period' => $periods[$index], 'amount' => $amount], $amounts, array_keys($amounts))];
    }

    /** @param list<array{period: AccountingPeriod, amount: string}> $schedule */
    private function writeSchedule(StaffAdvance $advance, array $schedule): void
    {
        foreach ($schedule as $index => $entry) {
            $advance->instalments()->create(['sequence' => $index + 1, 'accounting_period_id' => $entry['period']->id, 'amount' => $entry['amount'], 'recovered_amount' => '0.0000']);
        }
    }

    /** The stored schedule must still sum exactly to the principal. */
    private function assertSchedule(StaffAdvance $advance): void
    {
        $sum = $advance->instalments()->get()->reduce(fn (BigDecimal $total, $instalment): BigDecimal => $total->plus(AccountingDecimal::of($instalment->amount)), BigDecimal::zero()->toScale(4));
        if (! $sum->isEqualTo(AccountingDecimal::of($advance->principal_amount))) {
            throw ValidationException::withMessages(['status' => 'The recovery schedule no longer equals the principal. Edit the draft to regenerate it.']);
        }
    }

    /** A deduction component whose ledger may carry staff advances. */
    private function recoveryComponent(int $componentId): PayrollComponent
    {
        $component = PayrollComponent::query()->with('account')->find($componentId);
        if (! $component || $component->type !== PayrollComponentType::Deduction || ! $component->is_active) {
            throw ValidationException::withMessages(['payroll_component_id' => 'Select an active deduction component to recover the advance.']);
        }
        if (! $component->account) {
            throw ValidationException::withMessages(['payroll_component_id' => "Deduction component {$component->name} has no ledger. Set its staff advances ledger under Payroll Components first."]);
        }
        if (in_array((int) $component->account_id, array_map('intval', $this->guard->controlledAccountIds()), true)) {
            throw ValidationException::withMessages(['payroll_component_id' => "Deduction component {$component->name} points to a cash, bank or petty cash ledger and cannot recover an advance."]);
        }
        if (! $this->eligibleComponents()->contains('id', $component->id)) {
            throw ValidationException::withMessages(['payroll_component_id' => "The ledger of {$component->name} must be an active, postable asset or liability ledger."]);
        }

        return $component;
    }

    private function assertNotMaker(StaffAdvance $advance, User $actor): void
    {
        if (in_array($actor->id, $this->makers($advance), true)) {
            throw ValidationException::withMessages(['approval' => 'The user who prepared, edited or submitted a staff advance cannot approve or return it.']);
        }
    }

    /**
     * Creator, submitter and every user who edited the draft.
     *
     * @return list<int>
     */
    private function makers(StaffAdvance $advance): array
    {
        return array_values(array_unique(array_map('intval', array_filter([
            $advance->created_by, $advance->submitted_by, ...$this->audit->actorIds($advance, ['staff_advances.updated', 'staff_advances.submitted']),
        ]))));
    }

    private function lock(StaffAdvance $advance, User $actor): StaffAdvance
    {
        $advance = StaffAdvance::query()->with('branch')->lockForUpdate()->findOrFail($advance->id);
        abort_unless($this->branchAccess->canAccess($actor, $advance->branch), 403);

        return $advance;
    }

    private function authorize(User $actor, string ...$permissions): void
    {
        abort_unless($actor->is_active, 403);
        foreach ($permissions as $permission) {
            abort_unless($actor->can($permission), 403);
        }
    }

    private function record(StaffAdvance $advance, string $action, ?StaffAdvanceStatus $from, ?User $actor, ?string $comments = null): void
    {
        $advance->history()->create(['action' => $action, 'from_status' => $from?->value, 'to_status' => $advance->status->value, 'actor_id' => $actor?->id, 'comments' => $comments, 'created_at' => now()]);
    }
}
