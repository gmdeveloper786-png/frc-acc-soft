<?php

namespace App\Models;

use App\Enums\DepreciationRunStatus;
use App\Enums\FixedAssetStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\FixedAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['asset_code', 'name', 'category_id', 'description', 'acquisition_date', 'in_service_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'opening_accumulated_depreciation', 'branch_id', 'location', 'custodian', 'vendor_id', 'purchase_bill_id', 'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'status', 'created_by', 'activated_at', 'retired_at', 'retirement_reason'])]
class FixedAsset extends Model
{
    /** @use HasFactory<FixedAssetFactory> */
    use HasFactory;

    /** Terms that drive depreciation and posting; frozen once the asset leaves draft. */
    public const LOCKED_FIELDS = ['category_id', 'acquisition_date', 'in_service_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'opening_accumulated_depreciation', 'branch_id', 'purchase_bill_id', 'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id'];

    protected static function booted(): void
    {
        static::updating(function (FixedAsset $asset): void {
            if ($asset->isDirty('asset_code')) {
                throw new LogicException('A fixed asset code cannot be changed.');
            }
            $original = FixedAssetStatus::from((string) $asset->getRawOriginal('status'));
            if (! $original->isEditable() && $asset->isDirty(self::LOCKED_FIELDS)) {
                throw new LogicException('Cost, residual value, useful life, dates, branch and accounts of an activated fixed asset cannot be changed.');
            }
            if ($original === FixedAssetStatus::Retired) {
                throw new LogicException('A retired fixed asset cannot be changed.');
            }
        });
        static::deleting(function (FixedAsset $asset): void {
            if ($asset->status !== FixedAssetStatus::Draft) {
                throw new LogicException('An activated fixed asset cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => FixedAssetStatus::class, 'acquisition_date' => 'date', 'in_service_date' => 'date',
            'acquisition_cost' => 'decimal:4', 'residual_value' => 'decimal:4', 'opening_accumulated_depreciation' => 'decimal:4',
            'useful_life_months' => 'integer', 'activated_at' => 'datetime', 'retired_at' => 'datetime',
        ];
    }

    /** Cost less residual value: the most that may ever be depreciated. */
    public function depreciableAmount(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->acquisition_cost)->minus(AccountingDecimal::of($this->residual_value)));
    }

    /**
     * Opening accumulated depreciation plus the active lines of posted runs.
     * Never stored: it is always derived from posted history.
     */
    public function accumulatedDepreciation(): string
    {
        // List pages eager load postedLines so this does not run one query per asset.
        $lines = $this->relationLoaded('postedLines') ? $this->postedLines : $this->postedLines()->get(['depreciation_amount']);
        $accumulated = $lines
            ->reduce(fn (BigDecimal $sum, DepreciationRunLine $line): BigDecimal => $sum->plus(AccountingDecimal::of($line->depreciation_amount)), AccountingDecimal::of($this->opening_accumulated_depreciation));

        return AccountingDecimal::string($accumulated);
    }

    public function netBookValue(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->acquisition_cost)->minus(AccountingDecimal::of($this->accumulatedDepreciation())));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FixedAssetDocument::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FixedAssetEvent::class)->orderBy('id');
    }

    public function depreciationLines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class);
    }

    /** Lines that count towards accumulated depreciation. */
    public function postedLines(): HasMany
    {
        return $this->depreciationLines()->where('active_key', DepreciationRunLine::ACTIVE)
            ->whereHas('run', fn ($query) => $query->where('status', DepreciationRunStatus::Posted->value));
    }
}
