<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['depreciation_run_id', 'fixed_asset_id', 'accounting_period_id', 'depreciation_amount', 'accumulated_before', 'accumulated_after', 'net_book_value_after', 'active_key'])]
class DepreciationRunLine extends Model
{
    /** Value of active_key while the line counts; NULL releases the unique slot. */
    public const ACTIVE = '1';

    protected static function booted(): void
    {
        static::updating(function (DepreciationRunLine $line): void {
            if (array_diff(array_keys($line->getDirty()), ['active_key', 'updated_at']) !== []) {
                throw new LogicException('Depreciation amounts cannot be changed. Reject or reverse the run and prepare it again.');
            }
        });
    }

    protected function casts(): array
    {
        return ['depreciation_amount' => 'decimal:4', 'accumulated_before' => 'decimal:4', 'accumulated_after' => 'decimal:4', 'net_book_value_after' => 'decimal:4'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }
}
