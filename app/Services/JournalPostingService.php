<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class JournalPostingService
{
    public function __construct(
        private BranchAccess $branchAccess,
        private JournalValidationService $validator,
        private VoucherNumberService $numbers,
        private AuditLogger $audit,
        private ManualJournalGuard $manualGuard,
        private ApprovalAuthorityService $authority,
    ) {}

    /**
     * Post a journal that a user prepared. The manual-journal guard always
     * applies here, whatever source labels the journal carries, and so does
     * three-person separation: the poster is neither the preparer nor the
     * approver, and the approval is checked again against the current policy.
     */
    public function post(JournalEntry $journal, User $actor): JournalEntry
    {
        if ($journal->source_type === JournalReversalService::SOURCE_TYPE) {
            throw ValidationException::withMessages(['status' => 'A reversing journal is posted only through the reversal workflow.']);
        }
        $this->manualGuard->assertAllowed($journal);

        return $this->commit($journal, $actor, function (JournalEntry $journal, array $totals) use ($actor): array {
            if ($error = $this->posterSeparationError($journal, $actor)) {
                throw ValidationException::withMessages(['approval' => $error]);
            }
            $basis = $this->approvalBasis($journal, $totals['total_debit']);
            if ($basis === null) {
                throw ValidationException::withMessages(['approval' => 'The approval of this journal is no longer valid under the current approval policy. Return it so an eligible approver can approve it again.']);
            }

            return ['approved_by' => $journal->approved_by, 'approval_tier' => $basis === 'standard' ? 'standard' : 'director', 'authority_basis' => $basis];
        });
    }

    /**
     * Whether the user may post this approved manual journal now, for the
     * journal screen. post() checks everything again inside its lock.
     */
    public function canPost(JournalEntry $journal, User $actor): bool
    {
        return $journal->status === JournalStatus::Approved
            && $journal->voucher_number === null
            && $journal->source_type !== JournalReversalService::SOURCE_TYPE
            && $actor->is_active && $actor->can('journals.post')
            && $this->branchAccess->canAccess($actor, $journal->branch)
            && $this->posterSeparationError($journal, $actor) === null
            && $this->approvalBasis($journal, $this->lineDebitTotal($journal)) !== null;
    }

    /** Debit total recomputed from the journal lines, never the stored header figure. */
    private function lineDebitTotal(JournalEntry $journal): string
    {
        return AccountingDecimal::string($journal->lines()->pluck('debit')->reduce(
            fn ($sum, $debit) => $sum->plus(AccountingDecimal::of($debit)), AccountingDecimal::of('0'),
        ));
    }

    /** Why the user may not post this manual journal, or null. */
    private function posterSeparationError(JournalEntry $journal, User $actor): ?string
    {
        $preparers = [$journal->created_by, $journal->submitted_by, ...$this->audit->actorIds($journal, ['journal.edited'])];

        return match (true) {
            in_array($actor->id, $preparers, true) => 'You prepared this journal, so a different user must post it.',
            $actor->id === $journal->approved_by => 'You approved this journal, so a different user must post it.',
            default => null,
        };
    }

    /**
     * The authority under which the recorded approver may still approve this
     * amount, or null. The approver must exist, must not have prepared the
     * journal and must qualify today: active, with Director authority for the
     * branch above the threshold, or the approval permission and branch access
     * at or below it.
     */
    private function approvalBasis(JournalEntry $journal, mixed $amount): ?string
    {
        $approver = $journal->approved_by ? User::query()->find($journal->approved_by) : null;
        $preparers = [$journal->created_by, $journal->submitted_by, ...$this->audit->actorIds($journal, ['journal.edited'])];
        if (! $approver || in_array($approver->id, $preparers, true)) {
            return null;
        }

        return $this->authority->journalApprovalBasis($approver, $journal->branch, $amount);
    }

    /**
     * Post a journal built entirely by a trusted server-side service (purchase
     * bill, payment, opening balance, reversal, depreciation, payroll). Never
     * call this with lines or source references taken from user input.
     */
    public function postSystem(JournalEntry $journal, User $actor): JournalEntry
    {
        if (blank($journal->source_type) || blank($journal->source_key)) {
            throw new LogicException('A system journal must carry a server-assigned source type and key.');
        }

        return $this->commit($journal, $actor);
    }

    /**
     * $beforePosting runs extra checks inside the lock, after validation, and
     * returns extra details for the posting audit entry.
     *
     * @param  (callable(JournalEntry, array{total_debit: string, total_credit: string}): array<string, mixed>)|null  $beforePosting
     */
    private function commit(JournalEntry $journal, User $actor, ?callable $beforePosting = null): JournalEntry
    {
        abort_unless($actor->can('journals.post'), 403);

        return DB::transaction(function () use ($journal, $actor, $beforePosting): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            if ($journal->status !== JournalStatus::Approved || $journal->voucher_number !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted journal can be posted.']);
            }

            $year = FiscalYear::query()->lockForUpdate()->findOrFail($journal->fiscal_year_id);
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($journal->accounting_period_id);
            if ($year->status !== FiscalYearStatus::Open || $period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['accounting_period_id' => 'The fiscal year and accounting period must both be open.']);
            }

            $journal->setRelation('fiscalYear', $year)->setRelation('accountingPeriod', $period);
            $totals = $this->validator->validate($journal);
            $approval = $beforePosting ? $beforePosting($journal, $totals) : [];
            $voucherNumber = $this->numbers->next($journal->voucherType, $year);
            $journal->forceFill([
                ...$totals,
                'voucher_number' => $voucherNumber,
                'status' => JournalStatus::Posted,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();
            $this->audit->log('journal.posted', $journal, ['voucher_number' => $voucherNumber, ...$totals, ...$approval]);

            return $journal->refresh()->load(['lines.account', 'voucherType', 'branch']);
        }, 3);
    }
}
