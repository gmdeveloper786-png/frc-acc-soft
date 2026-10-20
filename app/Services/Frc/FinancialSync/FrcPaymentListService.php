<?php

namespace App\Services\Frc\FinancialSync;

use App\Models\FrcPayment;
use App\Models\User;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/** Filtering and SQL totals of the mirrored source payments. */
class FrcPaymentListService
{
    public const int PER_PAGE = 50;

    public const array POSTING_STATUSES = ['unposted', 'posted', 'needs_review', 'reversed'];

    public function __construct(private FrcMirrorAccess $access) {}

    /**
     * @param  array{branch_id?: ?int, service_id?: ?int, from?: ?string, to?: ?string, method?: ?string, status?: ?string, posting_status?: ?string, search?: ?string, unmapped?: bool}  $filters
     * @return Builder<FrcPayment>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $query = $this->access->payments($user);
        if (filled($filters['branch_id'] ?? null)) {
            $query->where('frc_payments.branch_id', (int) $filters['branch_id']);
        }
        if (filled($filters['service_id'] ?? null)) {
            $query->where('frc_payments.service_id', (int) $filters['service_id']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('frc_payments.payment_date', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('frc_payments.payment_date', '<=', $filters['to']);
        }
        foreach (['method' => 'payment_method', 'status' => 'source_status', 'posting_status' => 'posting_status'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where('frc_payments.'.$column, $filters[$filter]);
            }
        }
        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('frc_payments.receipt_number', 'like', '%'.addcslashes($search, '%_\\').'%');
                if (ctype_digit($search)) {
                    $builder->orWhere('frc_payments.frc_payment_id', (int) $search);
                }
            });
        }
        if ($filters['unmapped'] ?? false) {
            $query->where(fn (Builder $builder) => $builder->whereNull('frc_payments.branch_id')->orWhereNull('frc_payments.service_id'));
        }

        return $query;
    }

    /**
     * Totals of the filtered set, computed in SQL. Source-deleted rows are left out of the money totals.
     * Paid, cash and online follow the FRC Finance Report: only payments of approved, active or completed
     * enrollments count; paid payments of other enrollments are totalled separately as excluded.
     *
     * @param  Builder<FrcPayment>  $query
     * @return array{count: int, paid: string, cash: string, online: string, paid_excluded: string, pending_verification: string, pending_count: int}
     */
    public function summary(Builder $query): array
    {
        $live = 'frc_payments.source_deleted_at IS NULL';
        $cash = "'".implode("','", FrcPayment::CASH_METHODS)."'";
        $enrollmentStatus = 'COALESCE((SELECT fe.source_status FROM frc_enrollments fe WHERE fe.id = frc_payments.frc_enrollment_mirror_id), frc_payments.enrollment_status)';
        $counted = "{$live} AND frc_payments.source_status = 'paid' AND {$enrollmentStatus} IN ('".implode("','", FrcRecordNormalizer::ELIGIBLE_ENROLLMENT_STATUSES)."')";
        $row = (clone $query)->toBase()->selectRaw(implode(', ', [
            'COUNT(*) as record_count',
            "SUM(CASE WHEN {$counted} THEN frc_payments.amount ELSE 0 END) as paid_total",
            "SUM(CASE WHEN {$counted} AND frc_payments.payment_method IN ({$cash}) THEN frc_payments.amount ELSE 0 END) as cash_total",
            "SUM(CASE WHEN {$counted} AND frc_payments.payment_method NOT IN ({$cash}) THEN frc_payments.amount ELSE 0 END) as online_total",
            "SUM(CASE WHEN {$live} AND frc_payments.source_status = 'paid' AND NOT ({$counted}) THEN frc_payments.amount ELSE 0 END) as paid_excluded",
            "SUM(CASE WHEN {$live} AND frc_payments.source_status = 'pending_verification' THEN frc_payments.amount ELSE 0 END) as pending_total",
            "SUM(CASE WHEN {$live} AND frc_payments.source_status = 'pending_verification' THEN 1 ELSE 0 END) as pending_count",
        ]))->first();

        return [
            'count' => (int) ($row->record_count ?? 0),
            'paid' => self::decimal($row->paid_total ?? null),
            'cash' => self::decimal($row->cash_total ?? null),
            'online' => self::decimal($row->online_total ?? null),
            'paid_excluded' => self::decimal($row->paid_excluded ?? null),
            'pending_verification' => self::decimal($row->pending_total ?? null),
            'pending_count' => (int) ($row->pending_count ?? 0),
        ];
    }

    /** A database SUM of DECIMAL(19,4) columns as an exact four-decimal string. */
    public static function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }

    /** Two-decimal display with thousands separators, without floats. */
    public static function money(mixed $value): string
    {
        return AccountingDecimal::format($value, 2);
    }
}
