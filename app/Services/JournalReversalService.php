<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reversal of posted journals.
 *
 * A manual journal is reversed through a three-step workflow on the journal
 * screen: a requester creates a submitted reversing journal, a separate
 * approver approves it (Director authority above the journal threshold) and
 * a third user posts it. Only then does the original become Reversed.
 *
 * Journals posted by source documents (payments, bills, payroll,
 * depreciation, fee postings) are reversed by those documents' own workflows
 * through reverse(), which posts at once under that workflow's controls.
 */
class JournalReversalService
{
    public const SOURCE_TYPE = 'journal_reversal';

    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private BranchAccess $branchAccess,
        private JournalPostingService $posting,
        private JournalValidationService $validator,
        private ApprovalAuthorityService $authority,
        private AuditLogger $audit,
    ) {}

    /**
     * Step 1: request the reversal of a posted manual journal. Nothing is
     * posted and the original stays Posted.
     */
    public function request(JournalEntry $original, AccountingPeriod $period, string $date, string $reason, User $actor): JournalEntry
    {
        abort_unless($actor->is_active && $actor->can('journals.reverse'), 403);
        $this->assertReason($reason);

        return DB::transaction(function () use ($original, $period, $date, $reason, $actor): JournalEntry {
            $original = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($original->id);
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            $this->assertRequestable($original);
            $this->assertDateNotBefore($original, $date);

            $reversal = $this->build($original, $period, $date, $reason, $actor, JournalStatus::Submitted);
            $this->validator->validate($reversal);
            $this->audit->log('journal.reversal_requested', $original, $this->context($original, $reversal, ['reason' => trim($reason)]), $actor);

            return $reversal->fresh();
        }, 3);
    }

    /** Step 2: an independent approver approves the requested reversal. */
    public function approve(JournalEntry $reversal, User $actor): JournalEntry
    {
        abort_unless($actor->is_active && $actor->can('journals.approve'), 403);

        return DB::transaction(function () use ($reversal, $actor): JournalEntry {
            [$reversal, $original] = $this->lockPending($reversal, JournalStatus::Submitted, 'Only a requested reversal can be approved.');
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            if ($error = $this->approvalSeparationError($reversal, $original, $actor)) {
                throw ValidationException::withMessages(['approval' => $error]);
            }
            $this->assertDateNotBefore($original, $reversal->transaction_date->toDateString());
            $this->validator->validate($reversal);
            $basis = $this->authority->journalApprovalBasis($actor, $original->branch, $original->total_debit);
            if ($basis === null) {
                abort(403, $this->authority->exceedsJournalThreshold($original->total_debit)
                    ? 'Reversing a journal above the approval threshold needs a Director with authority for this branch.'
                    : 'You are not authorized to approve this reversal.');
            }

            $reversal->update(['status' => JournalStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->log('journal.reversal_approved', $original, $this->context($original, $reversal, ['authority_basis' => $basis]), $actor);

            return $reversal;
        }, 3);
    }

    /**
     * Step 3: a third user posts the approved reversal. All rules are checked
     * again, including that the approver still holds the authority used.
     */
    public function post(JournalEntry $reversal, User $actor): JournalEntry
    {
        abort_unless($actor->is_active && $actor->can('journals.post'), 403);

        return DB::transaction(function () use ($reversal, $actor): JournalEntry {
            [$reversal, $original] = $this->lockPending($reversal, JournalStatus::Approved, 'Only an approved reversal can be posted.');
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            if (in_array($actor->id, [$reversal->created_by, $reversal->approved_by], true)) {
                throw ValidationException::withMessages(['approval' => 'The user who requested or approved a reversal cannot post it.']);
            }
            $this->assertDateNotBefore($original, $reversal->transaction_date->toDateString());
            $approver = User::query()->find($reversal->approved_by);
            if (! $approver || $this->approvalSeparationError($reversal, $original, $approver) || $this->authority->journalApprovalBasis($approver, $original->branch, $original->total_debit) === null) {
                throw ValidationException::withMessages(['approval' => 'The approval is no longer valid. Return the reversal so an eligible approver can approve it again.']);
            }

            $posted = $this->posting->postSystem($reversal, $actor);
            $original->update(['status' => JournalStatus::Reversed, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reversal->reversal_reason]);
            $this->audit->log('journal.reversed', $original, $this->context($original, $posted, [
                'reason' => $reversal->reversal_reason, 'voucher_number' => $posted->voucher_number,
                'requested_by' => $reversal->created_by, 'approved_by' => $reversal->approved_by, 'posted_by' => $actor->id,
            ]), $actor);

            return $posted;
        }, 3);
    }

    /**
     * Return a requested or approved reversal. The unposted reversing journal
     * is removed so the original can be requested again; the audit log keeps
     * the full history.
     */
    public function returnRequest(JournalEntry $reversal, string $reason, User $actor): void
    {
        abort_unless($actor->is_active && ($actor->can('journals.approve') || $actor->can('journals.post')), 403);
        $this->assertReason($reason);

        DB::transaction(function () use ($reversal, $reason, $actor): void {
            [$reversal, $original] = $this->lockPending($reversal, null, 'Only a pending reversal can be returned.');
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            if ($actor->id === $reversal->created_by) {
                throw ValidationException::withMessages(['approval' => 'You requested this reversal. Cancel it instead of returning it.']);
            }
            $this->discardPending($reversal, $original, 'journal.reversal_returned', $reason, $actor);
        }, 3);
    }

    /** The requester withdraws their own pending reversal request. */
    public function cancel(JournalEntry $reversal, string $reason, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('journals.reverse'), 403);
        $this->assertReason($reason);

        DB::transaction(function () use ($reversal, $reason, $actor): void {
            [$reversal, $original] = $this->lockPending($reversal, null, 'Only a pending reversal can be cancelled.');
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            abort_unless($actor->id === $reversal->created_by, 403, 'Only the user who requested a reversal can cancel it.');
            $this->discardPending($reversal, $original, 'journal.reversal_cancelled', $reason, $actor);
        }, 3);
    }

    /**
     * What the user may do with the reversal workflow of this journal, for the
     * journal screen. The service checks everything again on submission.
     *
     * @return array{requestable: bool, pending: ?JournalEntry, isRequest: bool, canRequest: bool, canApprove: bool, canReturn: bool, canPost: bool, canCancel: bool, needsDirector: bool, blockedReason: ?string}
     */
    public function screenState(JournalEntry $journal, User $user): array
    {
        $isRequest = $journal->source_type === self::SOURCE_TYPE && in_array($journal->status, [JournalStatus::Submitted, JournalStatus::Approved], true);
        $original = $isRequest ? $journal->reversalOf : $journal;
        $pending = $isRequest ? $journal : $journal->reversal()->whereIn('status', [JournalStatus::Submitted->value, JournalStatus::Approved->value])->first();
        $blockedReason = $journal->status === JournalStatus::Posted ? $this->requestBlockedReason($journal) : null;
        $active = $user->is_active && $original && $this->branchAccess->canAccess($user, $original->branch);
        $state = [
            'requestable' => $journal->status === JournalStatus::Posted && $blockedReason === null,
            'pending' => $pending, 'isRequest' => $isRequest, 'blockedReason' => $blockedReason,
            'canRequest' => $active && $journal->status === JournalStatus::Posted && $blockedReason === null && $user->can('journals.reverse'),
            'canApprove' => false, 'canReturn' => false, 'canPost' => false, 'canCancel' => false,
            'needsDirector' => $original !== null && $this->authority->exceedsJournalThreshold($original->total_debit),
        ];
        if ($isRequest && $active) {
            $requester = $user->id === $journal->created_by;
            $state['canApprove'] = $journal->status === JournalStatus::Submitted && $this->approvalSeparationError($journal, $original, $user) === null
                && $this->authority->journalApprovalBasis($user, $original->branch, $original->total_debit) !== null;
            $state['canPost'] = $journal->status === JournalStatus::Approved && $user->can('journals.post') && ! in_array($user->id, [$journal->created_by, $journal->approved_by], true);
            $state['canReturn'] = ! $requester && ($user->can('journals.approve') || $user->can('journals.post'));
            $state['canCancel'] = $requester && $user->can('journals.reverse');
        }

        return $state;
    }

    /**
     * Why a posted journal cannot be reversed from the journal screen, or
     * null when it can.
     */
    public function requestBlockedReason(JournalEntry $original): ?string
    {
        return match (true) {
            $original->status !== JournalStatus::Posted => 'Only a posted journal can be reversed.',
            $original->source_type === self::SOURCE_TYPE => 'A reversing journal cannot be reversed. Record the transaction again through its own workflow.',
            $original->source_type === 'opening_balance' => 'Opening balance journals have no reversal workflow yet. They stay protected until a dedicated correction procedure is approved.',
            $original->source_type === YearEndClosingService::SOURCE_TYPE => 'Year-end closing journals have no reversal workflow yet. They stay protected until a dedicated reopening procedure is approved.',
            filled($original->source_type) => 'This journal was posted by a source document. Reverse that document through its own workflow instead.',
            $original->reversal()->exists() => 'A reversal of this journal is already pending or posted.',
            default => null,
        };
    }

    /**
     * Immediate reversal for source-document workflows (payments, bills,
     * payroll, depreciation, fee postings), which carry their own approval
     * controls. Not reachable from the journal screen.
     */
    public function reverse(JournalEntry $original, AccountingPeriod $period, string $date, string $reason, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.reverse'), 403);

        return DB::transaction(function () use ($original, $period, $date, $reason, $actor): JournalEntry {
            $original = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($original->id);
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            if ($original->status !== JournalStatus::Posted || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['status' => 'This journal is not eligible for another reversal.']);
            }
            $reversal = $this->build($original, $period, $date, $reason, $actor, JournalStatus::Approved);

            $posted = $this->posting->postSystem($reversal, $actor);
            $original->update(['status' => JournalStatus::Reversed, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->audit->log('journal.reversed', $original, ['reversal_journal_id' => $posted->id, 'reason' => $reason]);

            return $posted;
        }, 3);
    }

    /** The reversing journal: every line of the original with debit and credit swapped. */
    private function build(JournalEntry $original, AccountingPeriod $period, string $date, string $reason, User $actor, JournalStatus $status): JournalEntry
    {
        $approved = $status === JournalStatus::Approved;
        $reversal = JournalEntry::query()->create([
            'voucher_type_id' => $original->voucher_type_id,
            'transaction_date' => $date,
            'fiscal_year_id' => $period->fiscal_year_id,
            'accounting_period_id' => $period->id,
            'branch_id' => $original->branch_id,
            'narration' => 'Reversal of '.$original->voucher_number.': '.$reason,
            'reference_number' => $original->voucher_number,
            'source_system' => 'accounting',
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $original->id,
            'source_key' => self::SOURCE_TYPE.':'.$original->id,
            'status' => $status,
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
            'created_by' => $actor->id,
            'submitted_by' => $actor->id,
            'approved_by' => $approved ? $actor->id : null,
            'submitted_at' => now(),
            'approved_at' => $approved ? now() : null,
            'reversal_reason' => $reason,
            'reversal_of_id' => $original->id,
        ]);
        foreach ($original->lines as $line) {
            $reversal->lines()->create([
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'description' => $line->description,
                'service_id' => $line->service_id,
                'fund_id' => $line->fund_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ]);
        }

        return $reversal;
    }

    private function assertRequestable(JournalEntry $original): void
    {
        if ($reason = $this->requestBlockedReason($original)) {
            throw ValidationException::withMessages(['status' => $reason]);
        }
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Record a reason of at least '.self::MIN_REASON_LENGTH.' characters.']);
        }
    }

    private function assertDateNotBefore(JournalEntry $original, string $date): void
    {
        if ($date < $original->transaction_date->toDateString()) {
            throw ValidationException::withMessages(['transaction_date' => 'A reversal cannot be dated before the original journal ('.$original->transaction_date->format('d M Y').').']);
        }
    }

    /** Why this user may not approve the reversal, or null. */
    private function approvalSeparationError(JournalEntry $reversal, JournalEntry $original, User $actor): ?string
    {
        return match (true) {
            in_array($actor->id, [$reversal->created_by, $reversal->submitted_by], true) => 'You requested this reversal, so another user must approve it.',
            in_array($actor->id, [$original->created_by, $original->submitted_by], true) => 'You prepared the original journal, so another user must approve its reversal.',
            default => null,
        };
    }

    /**
     * Lock a pending reversing journal and its original. A null status
     * accepts either pending state.
     *
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    private function lockPending(JournalEntry $reversal, ?JournalStatus $status, string $message): array
    {
        $reversal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($reversal->id);
        $pending = $status ? [$status] : [JournalStatus::Submitted, JournalStatus::Approved];
        if ($reversal->source_type !== self::SOURCE_TYPE || $reversal->reversal_of_id === null || ! in_array($reversal->status, $pending, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
        $original = JournalEntry::query()->lockForUpdate()->findOrFail($reversal->reversal_of_id);
        if ($original->status !== JournalStatus::Posted) {
            throw ValidationException::withMessages(['status' => 'The original journal is no longer posted.']);
        }

        return [$reversal, $original];
    }

    private function discardPending(JournalEntry $reversal, JournalEntry $original, string $event, string $reason, User $actor): void
    {
        $this->audit->log($event, $original, $this->context($original, $reversal, [
            'reason' => trim($reason), 'request_reason' => $reversal->reversal_reason, 'requested_by' => $reversal->created_by,
            'approved_by' => $reversal->approved_by, 'status' => $reversal->status->value,
        ]), $actor);
        $reversal->lines()->delete();
        $reversal->delete();
    }

    /** @return array<string, mixed> */
    private function context(JournalEntry $original, JournalEntry $reversal, array $extra = []): array
    {
        return [
            'original_journal_id' => $original->id, 'original_voucher_number' => $original->voucher_number,
            'reversal_journal_id' => $reversal->id, 'branch_id' => $original->branch_id,
            'amount' => (string) $original->total_debit, 'transaction_date' => $reversal->transaction_date?->toDateString(),
            'approval_tier' => $this->authority->exceedsJournalThreshold($original->total_debit) ? 'director' : 'standard',
            ...$extra,
        ];
    }
}
