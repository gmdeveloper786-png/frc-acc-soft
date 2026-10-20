<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Models\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalancePostingService
{
    public const MIN_RETURN_REASON_LENGTH = 10;

    public function __construct(
        private BranchAccess $branchAccess,
        private JournalPostingService $posting,
        private OpeningBalanceValidationService $validator,
        private AuditLogger $audit,
        private OpeningBalanceImportService $imports,
    ) {}

    public function approve(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->can('opening_balances.approve'), 403);

        return DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->with(['fiscalYear', 'lines.account'])->lockForUpdate()->findOrFail($batch->id);
            abort_unless($this->branchAccess->canAccess($actor, $batch->branch), 403);
            if ($batch->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft opening balance can be approved.']);
            }
            $this->validator->assertBatchIsValid($batch);
            if (in_array($actor->id, $this->preparerIds($batch), true)) {
                throw ValidationException::withMessages(['status' => 'A separate authorized user must approve a draft opening balance.']);
            }
            $batch->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'rejection_reason' => null]);
            $this->audit->log('opening_balance.approved', $batch, [], $actor);

            return $batch;
        });
    }

    public function post(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->can('opening_balances.post'), 403);

        return DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->with(['fiscalYear', 'lines.account'])->lockForUpdate()->findOrFail($batch->id);
            abort_unless($this->branchAccess->canAccess($actor, $batch->branch), 403);
            if ($batch->status !== 'approved' || $batch->journal_entry_id !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted opening balance can be posted.']);
            }
            if ($error = $this->posterSeparationError($batch, $actor)) {
                throw ValidationException::withMessages(['approval' => $error]);
            }
            if (! $this->approvalIsValid($batch)) {
                throw ValidationException::withMessages(['approval' => 'The approval of this opening balance is no longer valid: the approver is inactive, lost the approval permission or branch access, prepared the batch, or the batch changed after approval. It cannot be posted.']);
            }
            $this->validator->assertBatchIsValid($batch);
            $period = AccountingPeriod::query()
                ->where('fiscal_year_id', $batch->fiscal_year_id)
                ->whereDate('start_date', '<=', $batch->opening_date)
                ->whereDate('end_date', '>=', $batch->opening_date)
                ->firstOrFail();
            $type = VoucherType::query()->where('code', 'JV')->where('is_active', true)->firstOrFail();
            $journal = JournalEntry::query()->create([
                'voucher_type_id' => $type->id,
                'transaction_date' => $batch->opening_date,
                'fiscal_year_id' => $batch->fiscal_year_id,
                'accounting_period_id' => $period->id,
                'branch_id' => $batch->branch_id,
                'narration' => 'Opening balances for '.$batch->branch->name,
                'reference_number' => $batch->source_reference,
                'source_system' => 'accounting',
                'source_type' => 'opening_balance',
                'source_id' => (string) $batch->id,
                'source_key' => 'opening_balance:'.$batch->id,
                'status' => 'approved',
                'total_debit' => $batch->total_debit,
                'total_credit' => $batch->total_credit,
                'created_by' => $batch->created_by,
                'submitted_by' => $batch->created_by,
                'approved_by' => $batch->approved_by,
                'submitted_at' => $batch->approved_at,
                'approved_at' => $batch->approved_at,
            ]);
            foreach ($batch->lines as $index => $line) {
                $journal->lines()->create([
                    'line_number' => $index + 1,
                    'account_id' => $line->account_id,
                    'description' => $line->source_reference,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ]);
            }
            $journal = $this->posting->postSystem($journal, $actor);
            $batch->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now(), 'journal_entry_id' => $journal->id]);
            $this->audit->log('opening_balance.posted', $batch, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, 'approved_by' => $batch->approved_by], $actor);

            return $batch->refresh();
        }, 3);
    }

    /**
     * Whether the user may approve this draft now, for the screen. approve()
     * checks everything again inside its lock.
     */
    public function canApprove(OpeningBalanceBatch $batch, User $actor): bool
    {
        return $batch->status === 'draft' && $actor->is_active && $actor->can('opening_balances.approve')
            && $this->branchAccess->canAccess($actor, $batch->branch)
            && ! in_array($actor->id, $this->preparerIds($batch), true);
    }

    /**
     * Whether the user may post this approved batch now, for the screen.
     * post() checks everything again inside its lock.
     */
    public function canPost(OpeningBalanceBatch $batch, User $actor): bool
    {
        return $batch->status === 'approved' && $batch->journal_entry_id === null
            && $actor->is_active && $actor->can('opening_balances.post')
            && $this->branchAccess->canAccess($actor, $batch->branch)
            && $this->posterSeparationError($batch, $actor) === null
            && $this->approvalIsValid($batch);
    }

    /**
     * Return an approved, unposted batch to draft, withdrawing its approval.
     * Amounts, lines, preparers and import links stay as they are; only a
     * new independent approval lets it be posted. Returning grants nothing:
     * it needs the approval permission and is refused to anyone who prepared
     * the batch, so a maker cannot clear the way for their own approval.
     */
    public function returnToDraft(OpeningBalanceBatch $batch, string $reason, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->is_active && $actor->can('opening_balances.approve'), 403);
        if (mb_strlen(trim($reason)) < self::MIN_RETURN_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Record a reason of at least '.self::MIN_RETURN_REASON_LENGTH.' characters.']);
        }

        return DB::transaction(function () use ($batch, $reason, $actor): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless($this->branchAccess->canAccess($actor, $batch->branch), 403);
            if ($batch->status !== 'approved' || $batch->journal_entry_id !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved opening balance that has not been posted can be returned to draft.']);
            }
            if (in_array($actor->id, $this->preparerIds($batch), true)) {
                throw ValidationException::withMessages(['approval' => 'You prepared this opening balance, so a different authorized reviewer must return it.']);
            }

            $approval = ['original_approver_id' => $batch->approved_by, 'approved_at' => $batch->approved_at?->toDateTimeString(), 'approval_was_valid' => $this->approvalIsValid($batch)];
            $batch->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => trim($reason)]);
            $this->audit->log('opening_balance.returned', $batch, [...$approval, 'old_status' => 'approved', 'new_status' => 'draft', 'reason' => trim($reason)], $actor);

            return $batch;
        });
    }

    /** Whether the user may return this approved batch to draft now, for the screen. */
    public function canReturn(OpeningBalanceBatch $batch, User $actor): bool
    {
        return $batch->status === 'approved' && $batch->journal_entry_id === null
            && $actor->is_active && $actor->can('opening_balances.approve')
            && $this->branchAccess->canAccess($actor, $batch->branch)
            && ! in_array($actor->id, $this->preparerIds($batch), true);
    }

    /**
     * Everyone who prepared the batch: its creator and every user who saved
     * it, including the last editor, and the uploader of the import applied to it.
     *
     * @return list<int>
     */
    private function preparerIds(OpeningBalanceBatch $batch): array
    {
        return collect([$batch->created_by, $batch->updated_by, $this->imports->uploaderOf($batch), ...$this->audit->actorIds($batch, ['opening_balance.draft_saved'])])
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /** Why the user may not post this batch, or null: the poster is neither a preparer nor the approver. */
    private function posterSeparationError(OpeningBalanceBatch $batch, User $actor): ?string
    {
        return match (true) {
            in_array($actor->id, $this->preparerIds($batch), true) => 'You prepared this opening balance, so a different user must post it.',
            $actor->id === (int) $batch->approved_by => 'You approved this opening balance, so a different user must post it.',
            default => null,
        };
    }

    /**
     * The recorded approval still stands: an approver exists, did not prepare
     * the batch, is active with the approval permission and branch access
     * today, and the batch was neither saved again nor returned after it was
     * approved.
     */
    public function approvalIsValid(OpeningBalanceBatch $batch): bool
    {
        $approver = $batch->approved_by ? User::query()->find($batch->approved_by) : null;
        if (! $approver || ! $approver->is_active || ! $approver->can('opening_balances.approve')
            || ! $this->branchAccess->canAccess($approver, $batch->branch)
            || in_array($approver->id, $this->preparerIds($batch), true)) {
            return false;
        }
        $events = fn (string $event) => AuditLog::query()->where('auditable_type', $batch->getMorphClass())->where('auditable_id', $batch->getKey())->where('event', $event)->max('id');
        $lastChanged = max($events('opening_balance.draft_saved') ?? 0, $events('opening_balance.returned') ?? 0);
        $lastApproved = $events('opening_balance.approved');

        return $lastChanged === 0 || ($lastApproved !== null && $lastApproved > $lastChanged);
    }
}
