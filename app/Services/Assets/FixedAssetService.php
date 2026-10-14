<?php

namespace App\Services\Assets;

use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\FixedAsset;
use App\Models\FixedAssetDocument;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The fixed asset register. Nothing in this service posts to the General
 * Ledger: acquisition cost is recognised by the purchase bill (or opening
 * balances) and must never be recognised a second time here.
 */
class FixedAssetService
{
    public const DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const DOCUMENT_MAX_KILOBYTES = 5120;

    private const DECIMAL_FIELDS = ['acquisition_cost', 'residual_value', 'opening_accumulated_depreciation'];

    private const DATE_FIELDS = ['acquisition_date', 'in_service_date'];

    public function __construct(
        private BranchAccess $branchAccess,
        private AssetCategoryService $categories,
        private AssetPolicy $policy,
        private DepreciationCalculator $calculator,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(array $data, User $actor): FixedAsset
    {
        abort_unless($actor->is_active && $actor->can('fixed_assets.create'), 403);

        return DB::transaction(function () use ($data, $actor): FixedAsset {
            $attributes = $this->draftAttributes($data, $actor);
            $asset = FixedAsset::query()->create([...$attributes, 'asset_code' => $this->nextAssetCode(), 'status' => FixedAssetStatus::Draft, 'created_by' => $actor->id]);
            $this->record($asset, 'registered', $actor, "Registered as a draft with cost {$asset->acquisition_cost}. No accounting entry was posted.");
            $this->audit->log('fixed_assets.registered', $asset, ['asset_code' => $asset->asset_code, 'branch_id' => $asset->branch_id, 'category_id' => $asset->category_id, 'acquisition_cost' => $asset->acquisition_cost]);

            return $asset;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(FixedAsset $asset, array $data, User $actor): FixedAsset
    {
        abort_unless($actor->is_active && $actor->can('fixed_assets.update'), 403);

        return DB::transaction(function () use ($asset, $data, $actor): FixedAsset {
            $asset = $this->lock($asset, $actor);
            if ($asset->status === FixedAssetStatus::Retired) {
                throw ValidationException::withMessages(['status' => 'A retired fixed asset cannot be edited.']);
            }

            if ($asset->status->isEditable()) {
                $asset->update($this->draftAttributes($data, $actor));
            } else {
                $changed = $this->changedLockedFields($asset, $data);
                if ($changed !== []) {
                    throw ValidationException::withMessages(['status' => 'This asset is active: '.implode(', ', $changed).' cannot be changed. Only name, description, location and custodian may be updated.']);
                }
                $asset->update(collect($data)->only(['name', 'description', 'location', 'custodian'])->all());
            }
            $changes = collect($asset->getChanges())->except('updated_at')->keys()->all();
            $this->record($asset, 'updated', $actor, $changes === [] ? 'Saved without changes.' : 'Changed: '.implode(', ', $changes).'.');
            $this->audit->log('fixed_assets.updated', $asset, ['asset_code' => $asset->asset_code, 'changed' => $changes]);

            return $asset;
        });
    }

    /**
     * Put a draft asset into use. Only the status changes: no journal is
     * created, because the cost is already in the ledger through the bill.
     */
    public function activate(FixedAsset $asset, User $actor): FixedAsset
    {
        abort_unless($actor->is_active && $actor->can('fixed_assets.update'), 403);

        return DB::transaction(function () use ($asset, $actor): FixedAsset {
            $asset = $this->lock($asset, $actor);
            if ($asset->status !== FixedAssetStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft fixed asset can be activated.']);
            }
            $this->assertFinancials($asset->only([...self::DECIMAL_FIELDS, ...self::DATE_FIELDS, 'useful_life_months']));
            $this->categories->assertAccounts($asset->asset_account_id, $asset->accumulated_depreciation_account_id, $asset->depreciation_expense_account_id);

            $threshold = $this->policy->capitalizationThreshold();
            if ($this->policy->isApproved() && $threshold->isPositive() && AccountingDecimal::of($asset->acquisition_cost)->isLessThan($threshold)) {
                throw ValidationException::withMessages(['acquisition_cost' => "The cost {$asset->acquisition_cost} is below the approved capitalization threshold of {$threshold}. This item should be expensed, not capitalized."]);
            }

            $fullyDepreciated = AccountingDecimal::of($asset->opening_accumulated_depreciation)->isEqualTo($this->calculator->depreciableAmount($asset));
            $asset->update(['status' => $fullyDepreciated ? FixedAssetStatus::FullyDepreciated : FixedAssetStatus::Active, 'activated_at' => now()]);
            $this->record($asset, 'activated', $actor, 'Activated. Financial terms and accounts are now frozen. No accounting entry was posted.');
            $this->audit->log('fixed_assets.activated', $asset, ['asset_code' => $asset->asset_code, 'acquisition_cost' => $asset->acquisition_cost, 'policy_approved' => $this->policy->isApproved()]);

            return $asset;
        });
    }

    /** Retirement changes the status only; it posts no disposal entry. */
    public function retire(FixedAsset $asset, string $reason, User $actor): FixedAsset
    {
        abort_unless($actor->is_active && $actor->can('fixed_assets.retire'), 403);

        return DB::transaction(function () use ($asset, $reason, $actor): FixedAsset {
            $asset = $this->lock($asset, $actor);
            if (! in_array($asset->status, [FixedAssetStatus::Active, FixedAssetStatus::FullyDepreciated], true)) {
                throw ValidationException::withMessages(['status' => 'Only an active or fully depreciated fixed asset can be retired.']);
            }
            $netBookValue = $asset->netBookValue();
            $asset->update(['status' => FixedAssetStatus::Retired, 'retired_at' => now(), 'retirement_reason' => $reason]);
            $this->record($asset, 'retired', $actor, "Retired with net book value {$netBookValue}. Reason: {$reason}. No disposal entry was posted.");
            $this->audit->log('fixed_assets.retired', $asset, ['asset_code' => $asset->asset_code, 'net_book_value' => $netBookValue, 'reason' => $reason]);

            return $asset;
        });
    }

    public function addDocument(FixedAsset $asset, UploadedFile $file, User $actor): FixedAssetDocument
    {
        abort_unless($actor->is_active && $actor->can('fixed_assets.update'), 403);
        abort_unless($this->branchAccess->canAccess($actor, $asset->branch), 403);
        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension()));
        if (! $file->isValid() || ! in_array($extension, self::DOCUMENT_EXTENSIONS, true) || $file->getSize() > self::DOCUMENT_MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['document' => 'Upload a PDF, JPG, PNG or WEBP file of at most 5 MB.']);
        }

        $path = $file->store('fixed-assets/'.$asset->id, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['document' => 'The document could not be stored.']);
        }

        return DB::transaction(function () use ($asset, $file, $path, $actor): FixedAssetDocument {
            $document = $asset->documents()->create([
                'disk' => 'local', 'path' => $path, 'original_name' => str($file->getClientOriginalName())->limit(250, '')->toString(),
                'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'), 'size_bytes' => (int) $file->getSize(), 'uploaded_by' => $actor->id,
            ]);
            $this->record($asset, 'document_added', $actor, 'Document added: '.$document->original_name);
            $this->audit->log('fixed_assets.document_added', $asset, ['asset_code' => $asset->asset_code, 'document_id' => $document->id, 'original_name' => $document->original_name]);

            return $document;
        });
    }

    /**
     * Posted depreciation (from history) followed by a projection of the
     * remaining life under the given convention.
     *
     * @return array{history: list<array<string, mixed>>, projection: list<array<string, mixed>>, convention: string}
     */
    public function schedule(FixedAsset $asset): array
    {
        $convention = $this->policy->convention();
        $lines = $asset->postedLines()->with('accountingPeriod')->get()->sortBy(fn ($line) => $line->accountingPeriod->start_date->toDateString())->values();
        $history = $lines->map(fn ($line): array => [
            'period_start' => $line->accountingPeriod->start_date, 'period_end' => $line->accountingPeriod->end_date, 'period_name' => $line->accountingPeriod->name,
            'amount' => $line->depreciation_amount, 'accumulated_after' => $line->accumulated_after, 'net_book_value_after' => $line->net_book_value_after,
            'run_id' => $line->depreciation_run_id,
        ])->all();

        $projection = $asset->status === FixedAssetStatus::Retired
            ? []
            : $this->calculator->projection($asset, AccountingDecimal::of($asset->accumulatedDepreciation()), $this->projectionStart($asset, $lines->last()?->accountingPeriod), $convention);

        return ['history' => $history, 'projection' => $projection, 'convention' => $convention->label()];
    }

    private function projectionStart(FixedAsset $asset, ?AccountingPeriod $lastPosted): CarbonImmutable
    {
        $first = $this->calculator->firstDepreciationMonth($asset, $this->policy->convention());
        if ($lastPosted) {
            return CarbonImmutable::parse($lastPosted->end_date)->addDay()->startOfMonth()->max($first);
        }
        if (AccountingDecimal::of($asset->opening_accumulated_depreciation)->isPositive()) {
            // Depreciation before the system is in the opening figure; project from the first period still open.
            $open = AccountingPeriod::query()->where('status', 'open')->orderBy('start_date')->value('start_date');

            return CarbonImmutable::parse($open ?? now())->startOfMonth()->max($first);
        }

        return $first;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function draftAttributes(array $data, User $actor): array
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        $category = AssetCategory::query()->whereKey($data['category_id'])->where('is_active', true)->first();
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Select an active asset category.']);
        }
        if (filled($data['vendor_id'] ?? null) && ! Vendor::query()->whereKey($data['vendor_id'])->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'The selected vendor does not exist.']);
        }
        if (filled($data['purchase_bill_id'] ?? null)) {
            $bill = PurchaseBill::query()->find($data['purchase_bill_id']);
            if (! $bill || (int) $bill->branch_id !== (int) $branch->id) {
                throw ValidationException::withMessages(['purchase_bill_id' => 'The purchase bill must belong to the same branch as the asset.']);
            }
        }
        $data['useful_life_months'] = (int) ($data['useful_life_months'] ?? $category->default_useful_life_months);
        $financials = $this->assertFinancials($data);

        return [
            'name' => trim((string) $data['name']), 'category_id' => $category->id, 'description' => $data['description'] ?? null,
            ...$financials, 'branch_id' => $branch->id, 'location' => $data['location'] ?? null, 'custodian' => $data['custodian'] ?? null,
            'vendor_id' => filled($data['vendor_id'] ?? null) ? (int) $data['vendor_id'] : null,
            'purchase_bill_id' => filled($data['purchase_bill_id'] ?? null) ? (int) $data['purchase_bill_id'] : null,
            'asset_account_id' => $category->asset_account_id,
            'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $category->depreciation_expense_account_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assertFinancials(array $data): array
    {
        $cost = $this->decimal($data['acquisition_cost'] ?? null, 'acquisition_cost');
        $residual = $this->decimal($data['residual_value'] ?? '0', 'residual_value');
        $opening = $this->decimal($data['opening_accumulated_depreciation'] ?? '0', 'opening_accumulated_depreciation');
        $life = (int) ($data['useful_life_months'] ?? 0);
        $acquired = Carbon::parse($data['acquisition_date'])->startOfDay();
        $inService = Carbon::parse($data['in_service_date'])->startOfDay();

        $errors = [];
        if (! $cost->isPositive()) {
            $errors['acquisition_cost'] = 'Acquisition cost must be greater than zero.';
        }
        if ($residual->isNegative() || $residual->isGreaterThan($cost)) {
            $errors['residual_value'] = 'Residual value must be between zero and the acquisition cost.';
        }
        if ($life < 1) {
            $errors['useful_life_months'] = 'Useful life must be at least one month.';
        }
        if ($opening->isNegative() || $opening->isGreaterThan($cost->minus($residual))) {
            $errors['opening_accumulated_depreciation'] = 'Opening accumulated depreciation must be between zero and the depreciable amount (cost less residual value).';
        }
        if ($inService->lt($acquired)) {
            $errors['in_service_date'] = 'The in-service date cannot be before the acquisition date.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'acquisition_date' => $acquired->toDateString(), 'in_service_date' => $inService->toDateString(),
            'acquisition_cost' => AccountingDecimal::string($cost), 'residual_value' => AccountingDecimal::string($residual),
            'useful_life_months' => $life, 'opening_accumulated_depreciation' => AccountingDecimal::string($opening),
        ];
    }

    /**
     * Frozen fields the caller is trying to change on an activated asset.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function changedLockedFields(FixedAsset $asset, array $data): array
    {
        $changed = [];
        foreach (FixedAsset::LOCKED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $submitted = $data[$field];
            $same = match (true) {
                in_array($field, self::DECIMAL_FIELDS, true) => $this->decimal($submitted ?? '0', $field)->isEqualTo(AccountingDecimal::of($asset->{$field})),
                in_array($field, self::DATE_FIELDS, true) => Carbon::parse($submitted)->toDateString() === $asset->{$field}->toDateString(),
                default => (blank($submitted) ? null : (int) $submitted) === ($asset->{$field} === null ? null : (int) $asset->{$field}),
            };
            if (! $same) {
                $changed[] = str_replace('_', ' ', $field);
            }
        }

        return $changed;
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        try {
            return AccountingDecimal::of($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter an amount with at most four decimal places.']);
        }
    }

    /** Server-side asset code from a locked sequence; must run inside a transaction. */
    private function nextAssetCode(): string
    {
        $lock = fn (): ?DocumentSequence => DocumentSequence::query()->where('document_type', 'fixed_asset')->where('scope_key', 'global')->lockForUpdate()->first();
        // Lock the existing row first. Inserting only when it is missing avoids the shared
        // lock an insert on an existing key takes, which deadlocks with the exclusive lock.
        $sequence = $lock();
        if (! $sequence) {
            DB::table('document_sequences')->insertOrIgnore(['document_type' => 'fixed_asset', 'scope_key' => 'global', 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = $lock() ?? throw new \RuntimeException('Asset code sequence could not be created.');
        }
        $number = $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        return 'FA-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function lock(FixedAsset $asset, User $actor): FixedAsset
    {
        $asset = FixedAsset::query()->with('branch')->lockForUpdate()->findOrFail($asset->id);
        abort_unless($this->branchAccess->canAccess($actor, $asset->branch), 403);

        return $asset;
    }

    private function record(FixedAsset $asset, string $event, User $actor, ?string $details = null): void
    {
        $asset->events()->create(['event' => $event, 'actor_id' => $actor->id, 'details' => $details, 'created_at' => now()]);
    }
}
