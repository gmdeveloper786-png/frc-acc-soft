<?php

namespace App\Services\Frc\FinancialSync;

use App\Models\FrcPayment;
use App\Models\FrcSyncException;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compares the mirrored FRC payments (with the Finance Report's rules) to the
 * fee postings. Read-only: it never posts, reverses or edits anything.
 */
class FrcReconciliationService
{
    public const string DiffMissingPosting = 'missing_posting';

    public const string DiffDuplicatePosting = 'duplicate_posting';

    public const string DiffAmountMismatch = 'amount_mismatch';

    public const string DiffPostedNotEligible = 'posted_not_eligible';

    public const string DiffUnmapped = 'unmapped';

    public const string DiffNeedsReview = 'needs_review';

    public const string DiffOpenException = 'open_exception';

    public const array DIFFERENCE_LABELS = [
        self::DiffMissingPosting => 'Eligible paid payment has no posted journal',
        self::DiffDuplicatePosting => 'Eligible paid payment is posted more than once',
        self::DiffAmountMismatch => 'Posted amount differs from the source amount',
        self::DiffPostedNotEligible => 'Posted, but no longer eligible (status, enrollment or deletion)',
        self::DiffUnmapped => 'Branch or service not mapped locally',
        self::DiffNeedsReview => 'Flagged for review after a source change',
        self::DiffOpenException => 'Open sync exception',
    ];

    public const string HISTORICAL_NOTE = 'The historical FRC Finance Report screenshot (7 payment records, PKR 18,000 paid, PKR 11,000 cash, PKR 7,000 online/bank) is a diagnostic reference only. It is not asserted here because the source data keeps changing.';

    /** Receipt-level rows shown on screen; exports include every row. */
    public const int SCREEN_ROW_LIMIT = 500;

    public function __construct(private FrcMirrorAccess $access) {}

    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, service_id?: ?int}  $scope
     * @return array<string, mixed>
     */
    public function reconcile(User $user, array $scope, ?int $rowLimit = self::SCREEN_ROW_LIMIT): array
    {
        $payments = $this->scopedPayments($user, $scope);
        $source = $this->sourceFigures(clone $payments);
        $perPayment = $this->perPayment(clone $payments);
        $ledger = $this->ledgerFigures($perPayment);
        $openExceptions = $this->openExceptions($user, $scope, clone $payments);

        $differenceQuery = DB::query()->fromSub($perPayment, 'p')->where(function (QueryBuilder $query): void {
            $query->whereRaw('(p.is_eligible = 1 AND p.posted_count <> 1)')
                ->orWhereRaw('(p.is_eligible = 1 AND p.posted_count = 1 AND ABS(p.posted_amount - p.amount) > 0.00005)')
                ->orWhereRaw('(p.is_eligible = 0 AND p.posted_count > 0)')
                ->orWhereNull('p.branch_id')->orWhereNull('p.service_id')
                ->orWhere('p.posting_status', FrcFinancialSyncService::PostingNeedsReview)
                ->orWhere('p.open_exceptions', '>', 0);
        })->orderBy('p.payment_date')->orderBy('p.frc_payment_id');
        $differenceTotal = (clone $differenceQuery)->count();
        $rowsQuery = clone $differenceQuery;
        if ($rowLimit !== null) {
            $rowsQuery->limit($rowLimit);
        }
        $rows = $rowsQuery->get()->map(fn (object $row): array => $this->differenceRow($row))->all();

        $counts = array_fill_keys(array_keys(self::DIFFERENCE_LABELS), 0);
        foreach ((clone $differenceQuery)->cursor() as $row) {
            foreach ($this->differenceRow($row)['types'] as $type) {
                $counts[$type]++;
            }
        }
        $counts[self::DiffOpenException] = max($counts[self::DiffOpenException], $openExceptions['count']);

        $hasRecords = $source['all_records'] > 0;
        $reconciled = $hasRecords && $differenceTotal === 0 && $openExceptions['count'] === 0;

        return [
            'status' => ! $hasRecords && $openExceptions['count'] === 0 ? 'empty' : ($reconciled ? 'reconciled' : 'not_reconciled'),
            'source' => $source,
            'ledger' => $ledger,
            'open_exceptions' => $openExceptions,
            'difference_counts' => $counts,
            'difference_total' => $differenceTotal,
            'rows' => $rows,
            'rows_truncated' => $rowLimit !== null && $differenceTotal > $rowLimit,
        ];
    }

    /** @param array<string, mixed> $result */
    public function exportTable(array $result, array $meta): ReportTable
    {
        $columns = [
            ['key' => 'item', 'label' => 'Item'],
            ['key' => 'receipt', 'label' => 'Receipt'],
            ['key' => 'frc_id', 'label' => 'FRC payment id'],
            ['key' => 'date', 'label' => 'Payment date'],
            ['key' => 'amount', 'label' => 'Source amount', 'type' => 'money'],
            ['key' => 'posted', 'label' => 'Posted amount', 'type' => 'money'],
            ['key' => 'detail', 'label' => 'Detail'],
        ];
        $source = $result['source'];
        $ledger = $result['ledger'];
        $figure = fn (string $label, ?string $amount, string $detail = ''): array => ['item' => $label, 'receipt' => '', 'frc_id' => '', 'date' => '', 'amount' => $amount ?? '', 'posted' => '', 'detail' => $detail];
        $rows = [
            ['item' => 'Status: '.$this->statusLabel($result['status']), 'receipt' => '', 'frc_id' => '', 'date' => '', 'amount' => '', 'posted' => '', 'detail' => '', '_style' => 'heading'],
            ['item' => 'Source (FRC mirror, Finance Report rules)', 'receipt' => '', 'frc_id' => '', 'date' => '', 'amount' => '', 'posted' => '', 'detail' => '', '_style' => 'heading'],
            $figure('Payment records of approved/active/completed enrollments', null, (string) $source['record_count']),
            $figure('Total paid', $source['paid_total'], $source['paid_count'].' '.Str::plural('payment', $source['paid_count'])),
            $figure('Cash received', $source['cash_total']),
            $figure('Online/bank received', $source['online_total']),
            $figure('Pending verification', $source['pending_total'], $source['pending_count'].' '.Str::plural('payment', $source['pending_count'])),
            $figure('Rejected', $source['rejected_total'], $source['rejected_count'].' '.Str::plural('payment', $source['rejected_count'])),
            $figure('Cancelled', $source['cancelled_total'], $source['cancelled_count'].' '.Str::plural('payment', $source['cancelled_count'])),
            $figure('Refunded', $source['refunded_total'], $source['refunded_count'].' '.Str::plural('payment', $source['refunded_count'])),
            $figure('Paid, enrollment not approved/active/completed (excluded)', $source['excluded_paid_total'], $source['excluded_paid_count'].' '.Str::plural('payment', $source['excluded_paid_count'])),
            ['item' => 'Accounting', 'receipt' => '', 'frc_id' => '', 'date' => '', 'amount' => '', 'posted' => '', 'detail' => '', '_style' => 'heading'],
            $figure('Eligible amount', $ledger['eligible_total'], $ledger['eligible_count'].' '.Str::plural('payment', $ledger['eligible_count'])),
            $figure('Posted amount', $ledger['posted_total']),
            $figure('Unposted eligible amount', $ledger['unposted_eligible_total'], $ledger['unposted_eligible_count'].' '.Str::plural('payment', $ledger['unposted_eligible_count'])),
            $figure('Reversed amount', $ledger['reversed_total']),
            $figure('Needs review', null, (string) $ledger['needs_review_count']),
            $figure('Unmapped payments', null, (string) $ledger['unmapped_count']),
            $figure('Open exceptions', null, (string) $result['open_exceptions']['count']),
            ['item' => 'Differences', 'receipt' => '', 'frc_id' => '', 'date' => '', 'amount' => '', 'posted' => '', 'detail' => '', '_style' => 'heading'],
        ];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'item' => implode('; ', array_map(fn (string $type): string => self::DIFFERENCE_LABELS[$type], $row['types'])),
                'receipt' => $row['receipt_number'] ?? '',
                'frc_id' => (string) $row['frc_payment_id'],
                'date' => $row['payment_date'],
                'amount' => $row['amount'],
                'posted' => $row['posted_amount'],
                'detail' => $row['source_status'].' / '.$row['payment_method'].($row['posted_count'] > 1 ? ' / '.$row['posted_count'].' postings' : ''),
            ];
        }
        if ($result['rows'] === []) {
            $rows[] = $figure('No receipt-level differences.', null);
        }

        return new ReportTable('FRC Fee Reconciliation', $columns, $rows, $meta, self::HISTORICAL_NOTE);
    }

    public function statusLabel(string $status): string
    {
        return ['reconciled' => 'Reconciled', 'not_reconciled' => 'Not reconciled', 'empty' => 'Nothing to reconcile'][$status] ?? $status;
    }

    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, service_id?: ?int}  $scope
     * @return Builder<FrcPayment>
     */
    private function scopedPayments(User $user, array $scope): Builder
    {
        $query = $this->access->payments($user);
        if (filled($scope['from'] ?? null)) {
            $query->whereDate('frc_payments.payment_date', '>=', $scope['from']);
        }
        if (filled($scope['to'] ?? null)) {
            $query->whereDate('frc_payments.payment_date', '<=', $scope['to']);
        }
        if (filled($scope['branch_id'] ?? null)) {
            $query->where('frc_payments.branch_id', (int) $scope['branch_id']);
        }
        if (filled($scope['service_id'] ?? null)) {
            $query->where('frc_payments.service_id', (int) $scope['service_id']);
        }

        return $query;
    }

    /** Effective enrollment status: the enrollment mirror, else the status nested in the payment. */
    private function enrollmentStatusSql(): string
    {
        return 'COALESCE(fe.source_status, frc_payments.enrollment_status)';
    }

    private function eligibleEnrollmentSql(): string
    {
        return $this->enrollmentStatusSql()." IN ('".implode("','", FrcRecordNormalizer::ELIGIBLE_ENROLLMENT_STATUSES)."')";
    }

    private function eligibleSql(): string
    {
        return "(frc_payments.source_status = 'paid' AND frc_payments.source_deleted_at IS NULL AND ".$this->eligibleEnrollmentSql().')';
    }

    /**
     * @param  Builder<FrcPayment>  $payments
     * @return array<string, int|string>
     */
    private function sourceFigures(Builder $payments): array
    {
        $live = 'frc_payments.source_deleted_at IS NULL';
        $eligibleEnrollment = $this->eligibleEnrollmentSql();
        $cash = "'".implode("','", FrcPayment::CASH_METHODS)."'";
        $paid = "{$live} AND frc_payments.source_status = 'paid' AND {$eligibleEnrollment}";
        $selects = [
            'COUNT(*) as all_records',
            "SUM(CASE WHEN {$live} AND {$eligibleEnrollment} THEN 1 ELSE 0 END) as record_count",
            "SUM(CASE WHEN {$paid} THEN 1 ELSE 0 END) as paid_count",
            "SUM(CASE WHEN {$paid} THEN frc_payments.amount ELSE 0 END) as paid_total",
            "SUM(CASE WHEN {$paid} AND frc_payments.payment_method IN ({$cash}) THEN frc_payments.amount ELSE 0 END) as cash_total",
            "SUM(CASE WHEN {$paid} AND frc_payments.payment_method NOT IN ({$cash}) THEN frc_payments.amount ELSE 0 END) as online_total",
            "SUM(CASE WHEN {$live} AND frc_payments.source_status = 'paid' AND NOT ({$eligibleEnrollment}) THEN 1 ELSE 0 END) as excluded_paid_count",
            "SUM(CASE WHEN {$live} AND frc_payments.source_status = 'paid' AND NOT ({$eligibleEnrollment}) THEN frc_payments.amount ELSE 0 END) as excluded_paid_total",
            'SUM(CASE WHEN frc_payments.source_deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted_count',
        ];
        foreach (['pending' => 'pending_verification', 'rejected' => 'rejected', 'cancelled' => 'cancelled', 'refunded' => 'refunded'] as $key => $status) {
            $selects[] = "SUM(CASE WHEN {$live} AND frc_payments.source_status = '{$status}' THEN 1 ELSE 0 END) as {$key}_count";
            $selects[] = "SUM(CASE WHEN {$live} AND frc_payments.source_status = '{$status}' THEN frc_payments.amount ELSE 0 END) as {$key}_total";
        }

        $row = (array) $this->withEnrollment($payments)->toBase()->selectRaw(implode(', ', $selects))->first();
        $figures = [];
        foreach ($row as $key => $value) {
            $figures[$key] = str_ends_with($key, '_total') ? FrcPaymentListService::decimal($value) : (int) $value;
        }

        return $figures;
    }

    /**
     * One row per scoped payment with its posting aggregates.
     *
     * @param  Builder<FrcPayment>  $payments
     */
    private function perPayment(Builder $payments): QueryBuilder
    {
        $postings = fn (string $aggregate, string $status): string => "(SELECT {$aggregate} FROM frc_fee_postings fp WHERE fp.frc_payment_id = frc_payments.id AND fp.status = '{$status}')";
        $exceptions = "(SELECT COUNT(*) FROM frc_sync_exceptions fx WHERE fx.resolved_at IS NULL AND ((fx.entity = 'payments' AND fx.external_id = frc_payments.frc_payment_id) OR (fx.entity = 'enrollments' AND fx.external_id = frc_payments.frc_enrollment_id)))";

        return $this->withEnrollment($payments)->toBase()->select([
            'frc_payments.id', 'frc_payments.frc_payment_id', 'frc_payments.receipt_number', 'frc_payments.payment_date', 'frc_payments.amount',
            'frc_payments.payment_method', 'frc_payments.source_status', 'frc_payments.posting_status', 'frc_payments.branch_id', 'frc_payments.service_id',
        ])->selectRaw($this->enrollmentStatusSql().' as effective_enrollment_status')
            ->selectRaw('CASE WHEN '.$this->eligibleSql().' THEN 1 ELSE 0 END as is_eligible')
            ->selectRaw($postings('COUNT(*)', 'posted').' as posted_count')
            ->selectRaw('COALESCE('.$postings('SUM(fp.amount)', 'posted').', 0) as posted_amount')
            ->selectRaw('COALESCE('.$postings('SUM(fp.amount)', 'reversed').', 0) as reversed_amount')
            ->selectRaw($exceptions.' as open_exceptions');
    }

    /** @return array<string, int|string> */
    private function ledgerFigures(QueryBuilder $perPayment): array
    {
        $row = (array) DB::query()->fromSub($perPayment, 'p')->selectRaw(implode(', ', [
            'SUM(CASE WHEN p.is_eligible = 1 THEN 1 ELSE 0 END) as eligible_count',
            'SUM(CASE WHEN p.is_eligible = 1 THEN p.amount ELSE 0 END) as eligible_total',
            'SUM(p.posted_amount) as posted_total',
            'SUM(CASE WHEN p.is_eligible = 1 AND p.posted_count = 0 THEN 1 ELSE 0 END) as unposted_eligible_count',
            'SUM(CASE WHEN p.is_eligible = 1 AND p.posted_count = 0 THEN p.amount ELSE 0 END) as unposted_eligible_total',
            'SUM(p.reversed_amount) as reversed_total',
            "SUM(CASE WHEN p.posting_status = 'needs_review' THEN 1 ELSE 0 END) as needs_review_count",
            'SUM(CASE WHEN p.branch_id IS NULL OR p.service_id IS NULL THEN 1 ELSE 0 END) as unmapped_count',
        ]))->first();

        $figures = [];
        foreach ($row as $key => $value) {
            $figures[$key] = str_ends_with($key, '_total') ? FrcPaymentListService::decimal($value) : (int) $value;
        }

        return $figures;
    }

    /**
     * Open exceptions of the scoped payments and their enrollments. For an
     * all-branch user without a branch or service filter, exceptions of records
     * that are not mirrored at all (invalid records) are included too.
     *
     * @param  Builder<FrcPayment>  $payments
     * @return array{count: int, unlinked: int}
     */
    private function openExceptions(User $user, array $scope, Builder $payments): array
    {
        $linked = FrcSyncException::query()->whereNull('resolved_at')->where(function (Builder $query) use ($payments): void {
            $query->where(fn (Builder $q) => $q->where('entity', FrcFinancialApi::Payments)->whereIn('external_id', (clone $payments)->select('frc_payments.frc_payment_id')))
                ->orWhere(fn (Builder $q) => $q->where('entity', FrcFinancialApi::Enrollments)->whereIn('external_id', (clone $payments)->select('frc_payments.frc_enrollment_id')));
        })->count();

        $unlinked = 0;
        if ($this->access->viewsAll($user) && blank($scope['branch_id'] ?? null) && blank($scope['service_id'] ?? null)) {
            $unlinked = FrcSyncException::query()->whereNull('resolved_at')
                ->where(fn (Builder $query) => $query->whereNull('external_id')
                    ->orWhere(fn (Builder $q) => $q->where('entity', FrcFinancialApi::Payments)->whereNotIn('external_id', FrcPayment::query()->select('frc_payment_id')))
                    ->orWhere(fn (Builder $q) => $q->where('entity', FrcFinancialApi::Enrollments)->whereNotIn('external_id', DB::table('frc_enrollments')->select('frc_enrollment_id'))))
                ->count();
        }

        return ['count' => $linked + $unlinked, 'unlinked' => $unlinked];
    }

    /**
     * @param  Builder<FrcPayment>  $payments
     * @return Builder<FrcPayment>
     */
    private function withEnrollment(Builder $payments): Builder
    {
        return $payments->leftJoin('frc_enrollments as fe', 'fe.id', '=', 'frc_payments.frc_enrollment_mirror_id');
    }

    /** @return array<string, mixed> */
    private function differenceRow(object $row): array
    {
        $amount = AccountingDecimal::of(FrcPaymentListService::decimal($row->amount));
        $posted = AccountingDecimal::of(FrcPaymentListService::decimal($row->posted_amount));
        $eligible = (int) $row->is_eligible === 1;
        $postedCount = (int) $row->posted_count;
        $types = [];
        if ($eligible && $postedCount === 0) {
            $types[] = self::DiffMissingPosting;
        }
        if ($eligible && $postedCount > 1) {
            $types[] = self::DiffDuplicatePosting;
        }
        if ($eligible && $postedCount === 1 && $posted->compareTo($amount) !== 0) {
            $types[] = self::DiffAmountMismatch;
        }
        if (! $eligible && $postedCount > 0) {
            $types[] = self::DiffPostedNotEligible;
        }
        if ($row->branch_id === null || $row->service_id === null) {
            $types[] = self::DiffUnmapped;
        }
        if ($row->posting_status === FrcFinancialSyncService::PostingNeedsReview) {
            $types[] = self::DiffNeedsReview;
        }
        if ((int) $row->open_exceptions > 0) {
            $types[] = self::DiffOpenException;
        }

        return [
            'id' => (int) $row->id,
            'frc_payment_id' => (int) $row->frc_payment_id,
            'receipt_number' => $row->receipt_number,
            'payment_date' => substr((string) $row->payment_date, 0, 10),
            'amount' => AccountingDecimal::string($amount),
            'posted_amount' => AccountingDecimal::string($posted),
            'posted_count' => $postedCount,
            'source_status' => $row->source_status,
            'payment_method' => $row->payment_method,
            'enrollment_status' => $row->effective_enrollment_status,
            'eligible' => $eligible,
            'types' => $types,
        ];
    }
}
