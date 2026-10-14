<?php

namespace App\Services\Frc\FinancialSync;

use App\Models\Branch;
use App\Models\FrcEnrollment;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Models\FrcSyncException;
use App\Models\FrcSyncRun;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Mirrors FRC enrollments and payments into frc_enrollments / frc_payments.
 *
 * Read-only towards FRC and towards the ledger: it never creates branches,
 * services or journals. A source change to an already posted payment only
 * flags the payment `needs_review` with an exception.
 */
class FrcFinancialSyncService
{
    public const string ModeFull = 'full';

    public const string ModeIncremental = 'incremental';

    public const string StatusRunning = 'running';

    public const string StatusCompleted = 'completed';

    public const string StatusFailed = 'failed';

    public const string PostingUnposted = 'unposted';

    public const string PostingPosted = 'posted';

    public const string PostingNeedsReview = 'needs_review';

    public const string CodeInvalidRecord = 'invalid_record';

    public const string CodeUnmappedBranch = 'unmapped_branch';

    public const string CodeUnmappedService = 'unmapped_service';

    public const string CodeChangedAfterPosting = 'changed_after_posting';

    public const array EXCEPTION_CODES = [self::CodeInvalidRecord, self::CodeUnmappedBranch, self::CodeUnmappedService, self::CodeChangedAfterPosting];

    /** Hard stop against a source that keeps answering has_more. */
    public const int MAX_PAGES = 1000;

    public const int LOCK_SECONDS = 1800;

    /** @var array<int, ?int> */
    private array $branchIds = [];

    /** @var array<int, ?int> */
    private array $serviceIds = [];

    public function __construct(private FrcFinancialApi $api, private FrcRecordNormalizer $normalizer, private AuditLogger $audit) {}

    public static function lockKey(string $entity): string
    {
        return 'frc-financial-sync:'.$entity;
    }

    /** Enrollments first, then payments. Stops at the first failed entity. */
    public function sync(string $mode, ?User $actor = null): FrcSyncOutcome
    {
        if (! in_array($mode, [self::ModeFull, self::ModeIncremental], true)) {
            throw new InvalidArgumentException('Unknown sync mode.');
        }

        /** @var list<Lock> $locks */
        $locks = [];
        foreach (FrcFinancialApi::ENTITIES as $entity) {
            $lock = Cache::lock(self::lockKey($entity), self::LOCK_SECONDS);
            if (! $lock->get()) {
                foreach ($locks as $held) {
                    $held->release();
                }

                return new FrcSyncOutcome(false, [], 'An FRC '.$entity.' sync is already running. Wait for it to finish, then try again.', alreadyRunning: true);
            }
            $locks[] = $lock;
        }

        $runs = [];
        try {
            $this->branchIds = [];
            $this->serviceIds = [];
            foreach (FrcFinancialApi::ENTITIES as $entity) {
                $run = $this->syncEntity($entity, $mode, $actor);
                $runs[] = $run;
                if ($run->status !== self::StatusCompleted) {
                    $skipped = $entity === FrcFinancialApi::Enrollments ? ' Payments were not synchronised.' : '';

                    return new FrcSyncOutcome(false, $runs, 'FRC '.$entity.' sync failed: '.$run->error.$skipped);
                }
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        $summary = collect($runs)->map(fn (FrcSyncRun $run): string => ucfirst($run->entity).': '.$run->fetched.' received, '.$run->created.' new, '.$run->updated.' updated, '.$run->unchanged.' unchanged, '.$run->failed.' failed')->implode('; ');

        return new FrcSyncOutcome(true, $runs, ucfirst($mode).' FRC sync completed. '.$summary.'.');
    }

    /** @return array{0: ?Carbon, 1: ?int} cursor of the last successful run of the entity */
    public function lastCursor(string $entity): array
    {
        $run = FrcSyncRun::query()->where('entity', $entity)->where('status', self::StatusCompleted)
            ->orderByDesc('finished_at')->orderByDesc('id')->first();

        return [$run?->cursor_to, $run?->cursor_to ? $run->cursor_to_id : null];
    }

    private function syncEntity(string $entity, string $mode, ?User $actor): FrcSyncRun
    {
        [$since, $afterId] = $mode === self::ModeIncremental ? $this->lastCursor($entity) : [null, null];
        $run = FrcSyncRun::query()->create([
            'entity' => $entity, 'mode' => $mode, 'status' => self::StatusRunning,
            'cursor_from' => $since, 'cursor_from_id' => $afterId,
            'triggered_by' => $actor?->getKey(), 'started_at' => now(),
        ]);
        $this->audit->log('frc_sync.started', $run, ['entity' => $entity, 'mode' => $mode]);

        $seenCursors = [];
        $lastRecord = null;
        try {
            while (true) {
                if ($run->pages >= self::MAX_PAGES) {
                    return $this->fail($run, 'Stopped after '.self::MAX_PAGES.' pages without reaching the end. Pages already saved are kept; the cursor was not advanced.');
                }
                $cursorKey = ($since?->getTimestamp() ?? 'start').':'.($afterId ?? '');
                if (isset($seenCursors[$cursorKey])) {
                    return $this->fail($run, 'FRC returned the same cursor twice, so the sync stopped to avoid an endless loop. Pages already saved are kept; the cursor was not advanced.');
                }
                $seenCursors[$cursorKey] = true;

                $page = $this->api->fetchPage($entity, $since, $afterId);
                if (! $page->successful) {
                    return $this->fail($run, (string) $page->error);
                }

                $counters = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
                DB::transaction(function () use ($entity, $page, $run, &$counters, &$lastRecord): void {
                    foreach ($page->records as $record) {
                        $result = $this->processRecord($entity, $record, $run);
                        $counters[$result['outcome']]++;
                        if ($result['cursor'] !== null && ($lastRecord === null || $this->cursorAfter($result['cursor'], $lastRecord))) {
                            $lastRecord = $result['cursor'];
                        }
                    }
                });

                $run->pages++;
                $run->fetched += count($page->records);
                foreach ($counters as $counter => $value) {
                    $run->{$counter} += $value;
                }
                $run->save();

                if (! $page->hasMore) {
                    [$since, $afterId] = $page->nextUpdatedSince !== null
                        ? [$page->nextUpdatedSince, $page->nextAfterId]
                        : ($lastRecord ?? [$since, $afterId]);
                    break;
                }
                if ($page->nextUpdatedSince === null) {
                    return $this->fail($run, 'FRC reported more '.$entity.' pages but sent no next cursor. Pages already saved are kept; the cursor was not advanced.');
                }
                [$since, $afterId] = [$page->nextUpdatedSince, $page->nextAfterId];
            }
        } catch (Throwable) {
            return $this->fail($run, 'The '.$entity.' sync stopped unexpectedly while saving page '.($run->pages + 1).'. Pages already saved are kept; the cursor was not advanced.');
        }

        $run->forceFill(['status' => self::StatusCompleted, 'cursor_to' => $since, 'cursor_to_id' => $since ? $afterId : null, 'finished_at' => now()])->save();
        $this->audit->log('frc_sync.completed', $run, ['entity' => $entity, 'mode' => $mode, ...$run->only(['pages', 'fetched', 'created', 'updated', 'unchanged', 'failed'])]);

        return $run;
    }

    private function fail(FrcSyncRun $run, string $error): FrcSyncRun
    {
        $run->forceFill(['status' => self::StatusFailed, 'error' => str($error)->limit(1000)->toString(), 'finished_at' => now()])->save();
        $this->audit->log('frc_sync.failed', $run, ['entity' => $run->entity, 'mode' => $run->mode, 'error' => $run->error, ...$run->only(['pages', 'fetched', 'created', 'updated', 'unchanged', 'failed'])]);

        return $run;
    }

    /**
     * @param  array{0: Carbon, 1: int}  $candidate
     * @param  array{0: Carbon, 1: int}  $current
     */
    private function cursorAfter(array $candidate, array $current): bool
    {
        return $candidate[0]->gt($current[0]) || ($candidate[0]->eq($current[0]) && $candidate[1] > $current[1]);
    }

    /** @return array{outcome: 'created'|'updated'|'unchanged'|'failed', cursor: ?array{0: Carbon, 1: int}} */
    private function processRecord(string $entity, mixed $record, FrcSyncRun $run): array
    {
        try {
            $data = $entity === FrcFinancialApi::Enrollments ? $this->normalizer->enrollment($record) : $this->normalizer->payment($record);
        } catch (InvalidFrcRecord $exception) {
            $this->exception($run, $entity, $this->normalizer->externalId($record), self::CodeInvalidRecord, $exception->getMessage(), dedupe: false);

            return ['outcome' => 'failed', 'cursor' => null];
        }

        $outcome = $entity === FrcFinancialApi::Enrollments ? $this->upsertEnrollment($data, $run) : $this->upsertPayment($data, $run);
        $id = $entity === FrcFinancialApi::Enrollments ? $data['frc_enrollment_id'] : $data['frc_payment_id'];

        return ['outcome' => $outcome, 'cursor' => [$data['source_updated_at'], $id]];
    }

    /** @param array<string, mixed> $data */
    private function upsertEnrollment(array $data, FrcSyncRun $run): string
    {
        $label = 'Enrollment '.$data['frc_enrollment_id'];
        $mapping = ['branch_id' => $this->localBranchId($data['frc_branch_id']), 'service_id' => $this->localServiceId($data['frc_service_id'])];
        $enrollment = FrcEnrollment::query()->where('frc_enrollment_id', $data['frc_enrollment_id'])->lockForUpdate()->first();

        if ($enrollment !== null && $this->isStale($enrollment->source_updated_at, $data['source_updated_at'])) {
            return 'unchanged';
        }
        $this->checkMapping($run, FrcFinancialApi::Enrollments, $data['frc_enrollment_id'], $label, $data, $mapping);

        if ($enrollment !== null && $enrollment->content_hash === $data['content_hash']) {
            $this->refreshMapping($enrollment, $mapping);

            return 'unchanged';
        }

        $outcome = $enrollment === null ? 'created' : 'updated';
        $enrollment ??= new FrcEnrollment;
        $enrollment->fill([...$data, ...$mapping, 'last_sync_run_id' => $run->getKey()])->save();
        $this->propagateEnrollment($enrollment, $run);

        return $outcome;
    }

    /** @param array<string, mixed> $data */
    private function upsertPayment(array $data, FrcSyncRun $run): string
    {
        $label = 'Payment '.$data['frc_payment_id'];
        $hasNestedEnrollment = $data['has_nested_enrollment'];
        unset($data['has_nested_enrollment']);

        $mirror = FrcEnrollment::query()->where('frc_enrollment_id', $data['frc_enrollment_id'])->first();
        if (! $hasNestedEnrollment && $mirror !== null) {
            $data = [...$data, 'frc_branch_id' => $mirror->frc_branch_id, 'frc_service_id' => $mirror->frc_service_id, 'enrollment_status' => $mirror->source_status];
        }
        $mapping = [
            'frc_enrollment_mirror_id' => $mirror?->getKey(),
            'branch_id' => $this->localBranchId($data['frc_branch_id']),
            'service_id' => $this->localServiceId($data['frc_service_id']),
        ];
        $payment = FrcPayment::query()->where('frc_payment_id', $data['frc_payment_id'])->lockForUpdate()->first();

        if ($payment !== null && $this->isStale($payment->source_updated_at, $data['source_updated_at'])) {
            return 'unchanged';
        }
        $this->checkMapping($run, FrcFinancialApi::Payments, $data['frc_payment_id'], $label, $data, $mapping);

        if ($payment !== null && $payment->content_hash === $data['content_hash']) {
            $this->refreshMapping($payment, $mapping);

            return 'unchanged';
        }

        if ($payment === null) {
            FrcPayment::query()->create([...$data, ...$mapping, 'posting_status' => self::PostingUnposted, 'last_sync_run_id' => $run->getKey()]);

            return 'created';
        }

        $changes = $this->postedChanges($payment, $data);
        $payment->fill([...$data, ...$mapping, 'last_sync_run_id' => $run->getKey()]);
        if ($changes !== []) {
            $this->flagForReview($payment, $run, $changes);
        }
        $payment->save();

        return 'updated';
    }

    /** An incoming record older than the stored one arrived out of order and is ignored. */
    private function isStale(?Carbon $stored, Carbon $incoming): bool
    {
        return $stored !== null && $incoming->lt($stored);
    }

    /**
     * Source fields that changed on a payment which already has a posted journal.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function postedChanges(FrcPayment $payment, array $data): array
    {
        $isPosted = in_array($payment->posting_status, [self::PostingPosted, self::PostingNeedsReview], true)
            || $payment->postings()->where('status', self::PostingPosted)->exists();
        if (! $isPosted) {
            return [];
        }

        $changes = [];
        if (AccountingDecimal::of($payment->amount)->compareTo(AccountingDecimal::of($data['amount'])) !== 0) {
            $changes[] = 'amount';
        }
        if ($payment->payment_method !== $data['payment_method']) {
            $changes[] = 'method';
        }
        if ($payment->payment_date?->toDateString() !== $data['payment_date']) {
            $changes[] = 'payment date';
        }
        if ($payment->source_status !== $data['source_status']) {
            $changes[] = 'status '.$payment->source_status.' → '.$data['source_status'];
        }
        if ($payment->source_deleted_at === null && $data['source_deleted_at'] !== null) {
            $changes[] = 'deleted in FRC';
        }
        if ((int) $payment->frc_branch_id !== (int) $data['frc_branch_id']) {
            $changes[] = 'branch';
        }

        return $changes;
    }

    /** @param list<string> $changes */
    private function flagForReview(FrcPayment $payment, FrcSyncRun $run, array $changes): void
    {
        $what = implode(', ', $changes);
        $payment->posting_status = self::PostingNeedsReview;
        $payment->posting_note = str('FRC changed '.$what.' after posting (sync run #'.$run->getKey().'). The journal was not changed; review and reverse if needed.')->limit(250)->toString();
        $this->exception($run, FrcFinancialApi::Payments, $payment->frc_payment_id, self::CodeChangedAfterPosting,
            'Payment '.$payment->frc_payment_id.': FRC changed '.$what.' after it was posted. The journal was not changed; review the posting.', dedupe: false);
    }

    /**
     * Pushes the enrollment's current branch, service and status to its mirrored
     * payments, as the Finance Report always uses the enrollment's current values.
     */
    private function propagateEnrollment(FrcEnrollment $enrollment, FrcSyncRun $run): void
    {
        $payments = FrcPayment::query()->where('frc_enrollment_id', $enrollment->frc_enrollment_id)->lockForUpdate()->get();
        foreach ($payments as $payment) {
            $payment->fill([
                'frc_enrollment_mirror_id' => $enrollment->getKey(),
                'enrollment_status' => $enrollment->source_status,
                'frc_branch_id' => $enrollment->frc_branch_id,
                'frc_service_id' => $enrollment->frc_service_id,
                'branch_id' => $enrollment->branch_id,
                'service_id' => $enrollment->service_id,
            ]);
            if ($payment->isDirty('frc_branch_id') && $payment->getOriginal('frc_branch_id') !== null) {
                $wasPosted = in_array($payment->posting_status, [self::PostingPosted, self::PostingNeedsReview], true)
                    || $payment->postings()->where('status', self::PostingPosted)->exists();
                if ($wasPosted) {
                    $this->flagForReview($payment, $run, ['branch (enrollment '.$enrollment->frc_enrollment_id.')']);
                }
            }
            if ($payment->isDirty()) {
                $payment->save();
            }
        }
    }

    /** @param array<string, ?int> $mapping */
    private function refreshMapping(FrcEnrollment|FrcPayment $record, array $mapping): void
    {
        $record->fill(array_filter($mapping, fn (?int $value): bool => $value !== null));
        if ($record->isDirty()) {
            $record->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, ?int>  $mapping
     */
    private function checkMapping(FrcSyncRun $run, string $entity, int $externalId, string $label, array $data, array $mapping): void
    {
        foreach (['branch' => self::CodeUnmappedBranch, 'service' => self::CodeUnmappedService] as $kind => $code) {
            if ($mapping[$kind.'_id'] !== null) {
                $this->resolveOpen($entity, $externalId, $code);

                continue;
            }
            $frcId = $data['frc_'.$kind.'_id'];
            $message = $frcId === null
                ? $label.': FRC sent no '.$kind.' id, so the record is unmapped.'
                : $label.': FRC '.$kind.' '.$frcId.' does not exist locally. Run the '.$kind.' sync; the record stays unmapped until then.';
            $this->exception($run, $entity, $externalId, $code, $message);
        }
    }

    private function exception(FrcSyncRun $run, string $entity, ?int $externalId, string $code, string $message, bool $dedupe = true): void
    {
        $values = ['frc_sync_run_id' => $run->getKey(), 'entity' => $entity, 'external_id' => $externalId, 'code' => $code, 'message' => str($message)->limit(490)->toString()];
        $open = $dedupe && $externalId !== null
            ? FrcSyncException::query()->where(['entity' => $entity, 'external_id' => $externalId, 'code' => $code])->whereNull('resolved_at')->first()
            : null;

        $open ? $open->fill($values)->save() : FrcSyncException::query()->create($values);
    }

    /** A mapping that now resolves closes the earlier unmapped exception automatically. */
    private function resolveOpen(string $entity, int $externalId, string $code): void
    {
        FrcSyncException::query()->where(['entity' => $entity, 'external_id' => $externalId, 'code' => $code])->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    private function localBranchId(?int $frcBranchId): ?int
    {
        if ($frcBranchId === null) {
            return null;
        }

        return $this->branchIds[$frcBranchId] ??= Branch::query()->where('frc_branch_id', $frcBranchId)->value('id');
    }

    private function localServiceId(?int $frcServiceId): ?int
    {
        if ($frcServiceId === null) {
            return null;
        }

        return $this->serviceIds[$frcServiceId] ??= FrcService::query()->where('frc_service_id', $frcServiceId)->value('id');
    }
}
