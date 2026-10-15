<?php

namespace App\Models;

use App\Enums\StaffAdvanceStatus;
use App\Enums\StaffAdvanceType;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\StaffAdvanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A staff advance or loan and its recovery schedule. The register never posts:
 * the payout is a linked payment of the payment workflow and recovery is the
 * recovery component deducted on posted payroll runs.
 */
#[Fillable(['reference', 'employee_id', 'branch_id', 'type', 'principal_amount', 'instalment_count', 'instalment_amount', 'first_recovery_period_id', 'payroll_component_id', 'purpose', 'notes', 'status', 'disbursement_transaction_id', 'created_by', 'submitted_by', 'approved_by', 'cancelled_by', 'disbursement_linked_by', 'submitted_at', 'approved_at', 'cancelled_at', 'disbursed_at', 'settled_at', 'rejection_reason', 'cancellation_reason'])]
class StaffAdvance extends Model
{
    /** @use HasFactory<StaffAdvanceFactory> */
    use HasFactory;

    /** Frozen once the advance leaves draft. */
    private const LOCKED_FIELDS = ['employee_id', 'branch_id', 'type', 'principal_amount', 'instalment_count', 'instalment_amount', 'first_recovery_period_id', 'payroll_component_id', 'created_by'];

    protected static function booted(): void
    {
        static::updating(function (StaffAdvance $advance): void {
            $original = StaffAdvanceStatus::from((string) $advance->getRawOriginal('status'));
            if (! $original->isEditable() && $advance->isDirty(self::LOCKED_FIELDS)) {
                throw new LogicException('The terms of a staff advance cannot change after it was submitted.');
            }
            if ($original->isDisbursed() && $advance->isDirty('disbursement_transaction_id')) {
                throw new LogicException('The disbursement of a staff advance cannot be replaced.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Staff advances are never deleted; cancel them instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => StaffAdvanceType::class, 'status' => StaffAdvanceStatus::class, 'principal_amount' => 'decimal:4', 'instalment_amount' => 'decimal:4', 'instalment_count' => 'integer',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'disbursed_at' => 'datetime', 'settled_at' => 'datetime',
        ];
    }

    /** Everything recovered through posted payroll runs. */
    public function recoveredAmount(): string
    {
        return AccountingDecimal::string($this->recoveredTotal());
    }

    /** Principal less everything recovered through posted payroll runs. */
    public function outstandingAmount(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->principal_amount)->minus($this->recoveredTotal()));
    }

    private function recoveredTotal(): BigDecimal
    {
        return $this->instalments->reduce(fn (BigDecimal $sum, StaffAdvanceInstalment $instalment): BigDecimal => $sum->plus(AccountingDecimal::of($instalment->recovered_amount)), BigDecimal::zero()->toScale(4));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function firstRecoveryPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'first_recovery_period_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(OperationalTransaction::class, 'disbursement_transaction_id');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(StaffAdvanceInstalment::class)->orderBy('sequence');
    }

    public function history(): HasMany
    {
        return $this->hasMany(StaffAdvanceHistory::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
