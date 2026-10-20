<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Funds\FundRestrictionPolicy;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalWorkflowService
{
    public function __construct(private BranchAccess $branchAccess, private JournalValidationService $validator, private AuditLogger $audit, private ManualJournalGuard $manualGuard, private FundRestrictionPolicy $fundRules, private ApprovalAuthorityService $authority) {}

    /** @param array<string,mixed> $data */
    public function create(array $data, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.create'), 403);

        return DB::transaction(function () use ($data, $actor): JournalEntry {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            $journal = JournalEntry::query()->create([...$this->header($data), 'status' => JournalStatus::Draft, 'created_by' => $actor->id]);
            $this->replaceLines($journal, $data['lines']);
            $this->audit->log('journal.created', $journal, ['branch_id' => $branch->id]);

            return $journal->load('lines');
        });
    }

    /** @param array<string,mixed> $data */
    public function update(JournalEntry $journal, array $data, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.update'), 403);

        return DB::transaction(function () use ($journal, $data, $actor): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            $this->assertNotReversalRequest($journal);
            if (! in_array($journal->status, [JournalStatus::Draft, JournalStatus::Rejected], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or returned journals can be edited.']);
            }
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            $journal->update([...$this->header($data), 'status' => JournalStatus::Draft, 'rejection_reason' => null]);
            $this->replaceLines($journal, $data['lines']);
            $this->audit->log('journal.edited', $journal, ['branch_id' => $branch->id], $actor);

            return $journal->load('lines');
        });
    }

    public function submit(JournalEntry $journal, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.submit'), 403);
        $this->manualGuard->assertAllowed($journal);

        return DB::transaction(function () use ($journal, $actor): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            $this->assertNotReversalRequest($journal);
            if ($journal->status !== JournalStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft journal can be submitted.']);
            }
            $totals = $this->validator->validate($journal);
            $journal->update([...$totals, 'status' => JournalStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->log('journal.submitted', $journal, $totals);

            return $journal;
        });
    }

    public function approve(JournalEntry $journal, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.approve'), 403);
        $this->manualGuard->assertAllowed($journal);

        return DB::transaction(function () use ($journal, $actor): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            $this->assertNotReversalRequest($journal);
            if ($journal->status !== JournalStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted journal can be approved.']);
            }
            if (in_array($actor->id, [$journal->created_by, $journal->submitted_by], true)) {
                throw ValidationException::withMessages(['approval' => 'The user who created or submitted a journal cannot approve it.']);
            }
            if (in_array($actor->id, $this->audit->actorIds($journal, ['journal.edited']), true)) {
                throw ValidationException::withMessages(['approval' => 'You edited this journal, so another user must approve it.']);
            }
            // The tier comes from the validated debit total, never from a stored or submitted figure.
            $totals = $this->validator->validate($journal);
            $basis = $this->authority->journalApprovalBasis($actor, $journal->branch, $totals['total_debit']);
            if ($basis === null) {
                abort(403, $this->authority->exceedsJournalThreshold($totals['total_debit'])
                    ? 'A journal above the approval threshold needs approval by a Director with authority for this branch.'
                    : 'You are not authorized to approve this journal.');
            }
            $journal->update(['status' => JournalStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'rejection_reason' => null]);
            $this->audit->log('journal.approved', $journal, ['amount' => $totals['total_debit'], 'approval_tier' => $basis === 'standard' ? 'standard' : 'director', 'authority_basis' => $basis]);

            return $journal;
        });
    }

    public function reject(JournalEntry $journal, User $actor, string $reason): JournalEntry
    {
        abort_unless($actor->can('journals.reject'), 403);

        return DB::transaction(function () use ($journal, $actor, $reason): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            $this->assertNotReversalRequest($journal);
            if (! in_array($journal->status, [JournalStatus::Submitted, JournalStatus::Approved], true)) {
                throw ValidationException::withMessages(['status' => 'This journal cannot be returned in its current state.']);
            }
            $journal->update(['status' => JournalStatus::Rejected, 'rejection_reason' => $reason, 'approved_by' => null, 'approved_at' => null]);
            $this->audit->log('journal.rejected', $journal, ['reason' => $reason]);

            return $journal;
        });
    }

    /**
     * Delete a manual journal that was never submitted or was returned. Nothing was posted,
     * so no financial history is lost; the deletion itself is audited.
     */
    public function discard(JournalEntry $journal, User $actor, string $reason): void
    {
        abort_unless($actor->can('journals.update'), 403);

        DB::transaction(function () use ($journal, $actor, $reason): void {
            $journal = JournalEntry::query()->lockForUpdate()->with('lines')->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            if (! in_array($journal->status, [JournalStatus::Draft, JournalStatus::Rejected], true) || $journal->voucher_number !== null || filled($journal->source_type)) {
                throw ValidationException::withMessages(['status' => 'Only a draft or returned manual journal can be discarded.']);
            }
            $this->audit->log('journal.discarded', $journal, [
                'branch_id' => $journal->branch_id, 'transaction_date' => $journal->transaction_date?->toDateString(),
                'narration' => $journal->narration, 'lines' => $journal->lines->count(), 'reason' => $reason,
            ], $actor);
            $journal->lines()->delete();
            $journal->delete();
        });
    }

    /**
     * Whether the user may approve this submitted manual journal now, for the
     * journal screen. approve() checks everything again.
     */
    public function canApprove(JournalEntry $journal, User $actor): bool
    {
        return $journal->status === JournalStatus::Submitted
            && $journal->source_type !== JournalReversalService::SOURCE_TYPE
            && ! in_array($actor->id, [$journal->created_by, $journal->submitted_by], true)
            && ! in_array($actor->id, $this->audit->actorIds($journal, ['journal.edited']), true)
            && $this->authority->journalApprovalBasis($actor, $journal->branch, $journal->total_debit) !== null;
    }

    /**
     * Every line, and the debit and credit totals, must fit what a journal can
     * hold. Checked before anything is written, so an oversized amount never
     * reaches the database.
     *
     * @param  list<array<string,mixed>>  $lines
     */
    private function assertWithinMaximum(array $lines): void
    {
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        foreach ($lines as $index => $line) {
            foreach (['debit', 'credit'] as $side) {
                try {
                    $amount = BigDecimal::of(trim((string) ($line[$side] ?? '')) ?: '0');
                } catch (\Throwable) {
                    throw ValidationException::withMessages(["lines.{$index}.{$side}" => 'Enter a valid decimal amount.']);
                }
                if (AccountingDecimal::exceedsMaximum($amount)) {
                    throw ValidationException::withMessages(["lines.{$index}.{$side}" => AccountingDecimal::maximumMessage()]);
                }
                $side === 'debit' ? $debit = $debit->plus($amount) : $credit = $credit->plus($amount);
            }
        }
        if (AccountingDecimal::exceedsMaximum($debit) || AccountingDecimal::exceedsMaximum($credit)) {
            throw ValidationException::withMessages(['lines' => AccountingDecimal::maximumMessage('The journal debit and credit totals')]);
        }
    }

    /** A reversal request moves only through the reversal workflow. */
    private function assertNotReversalRequest(JournalEntry $journal): void
    {
        if ($journal->source_type === JournalReversalService::SOURCE_TYPE) {
            throw ValidationException::withMessages(['status' => 'A reversing journal is approved, returned and posted only through the reversal workflow.']);
        }
    }

    /** @param array<string,mixed> $data */
    private function header(array $data): array
    {
        return collect($data)->only(['voucher_type_id', 'transaction_date', 'fiscal_year_id', 'accounting_period_id', 'branch_id', 'narration', 'reference_number'])->all();
    }

    /** @param list<array<string,mixed>> $lines */
    private function replaceLines(JournalEntry $journal, array $lines): void
    {
        // Restricted-fund rule (always enforced; posting checks it again).
        $this->fundRules->check(array_values($lines), fn (int|string $index): string => "lines.{$index}.account_id");
        $this->assertWithinMaximum(array_values($lines));
        $journal->lines()->delete();
        $debit = BigDecimal::zero()->toScale(4);
        $credit = BigDecimal::zero()->toScale(4);
        foreach (array_values($lines) as $index => $line) {
            $lineDebit = AccountingDecimal::of($line['debit'] ?? 0);
            $lineCredit = AccountingDecimal::of($line['credit'] ?? 0);
            $debit = $debit->plus($lineDebit);
            $credit = $credit->plus($lineCredit);
            $journal->lines()->create([
                'line_number' => $index + 1, 'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null, 'service_id' => $line['service_id'] ?? null,
                'fund_id' => $line['fund_id'] ?? null,
                'debit' => AccountingDecimal::string($lineDebit), 'credit' => AccountingDecimal::string($lineCredit),
            ]);
        }
        $journal->update(['total_debit' => AccountingDecimal::string($debit), 'total_credit' => AccountingDecimal::string($credit)]);
    }
}
