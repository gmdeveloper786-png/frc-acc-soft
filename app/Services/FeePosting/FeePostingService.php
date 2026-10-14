<?php

namespace App\Services\FeePosting;

use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Posts mirrored FRC fees to the Journal Engine. Posting is always explicit,
 * one journal and one database transaction per record, re-checked under a
 * row lock, and idempotent through the unique source key.
 */
class FeePostingService
{
    public const ALREADY_POSTED = 'Already posted: another posting of this record was recorded first. No second journal was created.';

    public function __construct(
        private FeePostingEligibility $eligibility,
        private JournalPostingService $journals,
        private JournalReversalService $reversals,
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
    ) {}

    /** Post one paid FRC payment (cash or accrual basis). */
    public function postPayment(FrcPayment $payment, User $actor): FrcFeePosting
    {
        $this->assertCanPost($actor);

        try {
            return DB::transaction(function () use ($payment, $actor): FrcFeePosting {
                $payment = FrcPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $this->assertBranch($actor, $payment->branch);
                $plan = $this->eligibility->forPayment($payment, $actor);
                $this->assertEligible($plan);

                $journal = $this->createJournal($plan, $actor, 'frc_payment', (string) $payment->id);
                $posting = FrcFeePosting::query()->create([
                    'kind' => 'payment', 'basis' => $plan->basis, 'frc_payment_id' => $payment->id, 'frc_enrollment_id' => $payment->frc_enrollment_mirror_id,
                    'amount' => $plan->amount, 'source_key' => $plan->sourceKey, 'journal_entry_id' => $journal->id, 'status' => 'posted', 'posted_by' => $actor->id,
                ]);
                $payment->forceFill(['posting_status' => 'posted', 'posting_note' => null])->save();
                $this->audit->log('fee_posting.payment_posted', $posting, [
                    'branch_id' => $payment->branch_id, 'frc_payment_id' => $payment->frc_payment_id, 'basis' => $plan->basis,
                    'amount' => $plan->amount, 'journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, 'source_key' => $plan->sourceKey,
                ]);

                return $posting->setRelation('journalEntry', $journal);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['posting' => self::ALREADY_POSTED]);
        }
    }

    /** Recognise an enrollment's fee under the accrual basis, or adjust it for a changed total. */
    public function recogniseEnrollment(FrcEnrollment $enrollment, User $actor): FrcFeePosting
    {
        $this->assertCanPost($actor);

        try {
            return DB::transaction(function () use ($enrollment, $actor): FrcFeePosting {
                $enrollment = FrcEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
                $this->assertBranch($actor, $enrollment->branch);
                $plan = $this->eligibility->forEnrollment($enrollment, $actor);
                $this->assertEligible($plan);

                $journal = $this->createJournal($plan, $actor, 'frc_enrollment', (string) $enrollment->id);
                $posting = FrcFeePosting::query()->create([
                    'kind' => 'enrollment', 'basis' => $plan->basis, 'frc_enrollment_id' => $enrollment->id,
                    'amount' => $plan->amount, 'source_key' => $plan->sourceKey, 'journal_entry_id' => $journal->id, 'status' => 'posted', 'posted_by' => $actor->id,
                ]);
                $this->audit->log('fee_posting.enrollment_recognised', $posting, [
                    'branch_id' => $enrollment->branch_id, 'frc_enrollment_id' => $enrollment->frc_enrollment_id, 'basis' => $plan->basis,
                    'amount' => $plan->amount, 'journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, 'source_key' => $plan->sourceKey,
                ]);

                return $posting->setRelation('journalEntry', $journal);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['posting' => self::ALREADY_POSTED]);
        }
    }

    /**
     * Post several records, each in its own transaction, so one failure never
     * rolls back the others. The batch and every skip are audited.
     *
     * @param  list<int>  $ids
     * @return array<int, array{status: string, message: string}>
     */
    public function postBatch(string $kind, array $ids, User $actor): array
    {
        $this->assertCanPost($actor);
        $results = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $posting = $kind === 'payment'
                    ? $this->postPayment(FrcPayment::query()->findOrFail($id), $actor)
                    : $this->recogniseEnrollment(FrcEnrollment::query()->findOrFail($id), $actor);
                $results[$id] = ['status' => 'posted', 'message' => 'Posted as '.$posting->journalEntry->voucher_number.'.'];
            } catch (ValidationException $exception) {
                $results[$id] = ['status' => 'skipped', 'message' => collect($exception->errors())->flatten()->implode(' ')];
            } catch (HttpExceptionInterface $exception) {
                $results[$id] = ['status' => 'skipped', 'message' => $exception->getStatusCode() === 403 ? 'You are not authorised to post this record.' : 'The record was not found.'];
            } catch (Throwable $exception) {
                report($exception);
                $results[$id] = ['status' => 'failed', 'message' => 'Unexpected error; nothing was posted for this record.'];
            }
        }

        $skipped = array_filter($results, fn (array $result): bool => $result['status'] !== 'posted');
        $this->audit->log('fee_posting.batch', null, [
            'kind' => $kind, 'requested' => count($results), 'posted' => count($results) - count($skipped), 'skipped' => count($skipped),
            'skipped_reasons' => array_map(fn (array $result): string => $result['message'], $skipped),
        ]);

        return $results;
    }

    /**
     * Reverse a posting through the Journal Engine reversal. The original
     * journal and its lines stay untouched; an opposite journal is posted.
     */
    public function reverse(FrcFeePosting $posting, AccountingPeriod $period, string $date, string $reason, User $actor): FrcFeePosting
    {
        abort_unless($actor->can('frc_integration.reverse') && $actor->can('journals.reverse'), 403);

        return DB::transaction(function () use ($posting, $period, $date, $reason, $actor): FrcFeePosting {
            $posting = FrcFeePosting::query()->with('journalEntry.branch')->lockForUpdate()->findOrFail($posting->id);
            $this->assertBranch($actor, $posting->journalEntry->branch);
            if ($posting->status !== 'posted' || $posting->journalEntry->status !== JournalStatus::Posted) {
                throw ValidationException::withMessages(['posting' => 'Only a posted fee posting can be reversed.']);
            }
            if ($posting->kind === 'enrollment' && FrcFeePosting::query()->where('kind', 'payment')->where('status', 'posted')->where('frc_enrollment_id', $posting->frc_enrollment_id)->exists()) {
                throw ValidationException::withMessages(['posting' => 'Payments of this enrollment are posted against its receivable. Reverse those payments first.']);
            }

            $reversal = $this->reversals->reverse($posting->journalEntry, $period, $date, $reason, $actor);
            $posting->update(['status' => 'reversed', 'reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id, 'reversal_reason' => $reason, 'reversed_at' => now()]);

            $mirrorStatus = null;
            if ($posting->kind === 'payment') {
                $payment = FrcPayment::query()->with('enrollment')->lockForUpdate()->findOrFail($posting->frc_payment_id);
                $mirrorStatus = $this->eligibility->sourceIssues($payment) === [] ? 'unposted' : 'reversed';
                $payment->forceFill(['posting_status' => $mirrorStatus, 'posting_note' => str('Posting reversed: '.$reason)->limit(250)->toString()])->save();
            }
            $this->audit->log('fee_posting.reversed', $posting, [
                'branch_id' => $posting->journalEntry->branch_id, 'kind' => $posting->kind, 'source_key' => $posting->source_key,
                'journal_entry_id' => $posting->journal_entry_id, 'reversal_journal_entry_id' => $reversal->id, 'reason' => $reason, 'mirror_posting_status' => $mirrorStatus,
            ]);

            return $posting->refresh();
        }, 3);
    }

    private function createJournal(FeePostingPlan $plan, User $actor, string $sourceType, string $sourceId): JournalEntry
    {
        $voucher = VoucherType::query()->where('code', $plan->voucherCode)->where('is_active', true)->first();
        if (! $voucher) {
            throw ValidationException::withMessages(['voucher_type' => "Voucher type {$plan->voucherCode} is not configured or inactive."]);
        }
        $total = collect($plan->lines)->first(fn (array $line): bool => $line['debit'] !== '0.0000')['debit'];
        $journal = JournalEntry::query()->create([
            'voucher_type_id' => $voucher->id, 'transaction_date' => $plan->date,
            'fiscal_year_id' => $plan->period->fiscal_year_id, 'accounting_period_id' => $plan->period->id,
            'branch_id' => $plan->branchId, 'narration' => $plan->narration, 'reference_number' => $plan->reference,
            'source_system' => 'frc', 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_key' => $plan->sourceKey,
            'status' => JournalStatus::Approved, 'total_debit' => $total, 'total_credit' => $total,
            'created_by' => $actor->id, 'submitted_by' => $actor->id, 'approved_by' => $actor->id,
            'submitted_at' => now(), 'approved_at' => now(),
        ]);
        foreach ($plan->lines as $index => $line) {
            $journal->lines()->create([
                'line_number' => $index + 1, 'account_id' => $line['account_id'], 'service_id' => $line['service_id'],
                'description' => $line['description'], 'debit' => $line['debit'], 'credit' => $line['credit'],
            ]);
        }

        return $this->journals->postSystem($journal, $actor);
    }

    private function assertCanPost(User $actor): void
    {
        abort_unless($actor->can('frc_integration.post') && $actor->can('journals.post'), 403);
    }

    private function assertBranch(User $actor, mixed $branch): void
    {
        if ($branch !== null) {
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        } elseif (! $actor->can('branches.view_all')) {
            abort(403);
        }
    }

    private function assertEligible(FeePostingPlan $plan): void
    {
        if ($plan->eligible()) {
            return;
        }
        if ($plan->has('already_posted')) {
            throw ValidationException::withMessages(['posting' => self::ALREADY_POSTED]);
        }

        throw ValidationException::withMessages(['posting' => array_values($plan->reasons)]);
    }
}
