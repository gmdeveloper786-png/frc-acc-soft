<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Master data of the inventory module: categories, items and branch locations.
 */
class InventoryCatalogService
{
    private const PERMISSION = 'inventory_items.manage';

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveCategory(array $data, User $actor, ?InventoryCategory $category = null): InventoryCategory
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $category): InventoryCategory {
            $attributes = ['name' => $data['name'], 'is_active' => (bool) ($data['is_active'] ?? true)];
            $event = $category ? 'inventory_categories.updated' : 'inventory_categories.created';
            $category = tap($category ?? new InventoryCategory)->fill($attributes);
            $category->save();
            $this->audit->log($event, $category, $attributes);

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function saveItem(array $data, User $actor, ?InventoryItem $item = null): InventoryItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor, $item): InventoryItem {
            $category = InventoryCategory::query()->find($data['category_id']);
            if (! $category || (! $category->is_active && $item?->category_id !== $category->id)) {
                throw ValidationException::withMessages(['category_id' => 'Select an active inventory category.']);
            }
            $attributes = [
                'item_code' => $data['item_code'], 'name' => $data['name'], 'category_id' => $category->id,
                'unit_of_measure' => $data['unit_of_measure'],
                'minimum_stock_level' => AccountingDecimal::string(AccountingDecimal::of($data['minimum_stock_level'] ?? '0')),
                'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if ($item) {
                $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
                // Recorded quantities are expressed in the unit they were entered in.
                if ($item->unit_of_measure !== $attributes['unit_of_measure'] && $item->movements()->exists()) {
                    throw ValidationException::withMessages(['unit_of_measure' => 'The unit of measure cannot change once stock movements exist for this item.']);
                }
                $item->update($attributes);
                $this->audit->log('inventory_items.updated', $item, $attributes);

                return $item;
            }

            $item = InventoryItem::query()->create([...$attributes, 'created_by' => $actor->id]);
            $this->audit->log('inventory_items.created', $item, $attributes);

            return $item;
        });
    }

    /**
     * A location stays in the branch it was created for.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveLocation(array $data, User $actor, ?InventoryLocation $location = null): InventoryLocation
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor, $location): InventoryLocation {
            $branch = Branch::query()->findOrFail($location ? $location->branch_id : $data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

            $attributes = ['branch_id' => $branch->id, 'name' => $data['name'], 'is_active' => (bool) ($data['is_active'] ?? true)];
            if (InventoryLocation::query()->where('branch_id', $branch->id)->where('name', $attributes['name'])->when($location, fn ($query) => $query->whereKeyNot($location->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This branch already has a location with that name.']);
            }
            $event = $location ? 'inventory_locations.updated' : 'inventory_locations.created';
            $location = tap($location ?? new InventoryLocation)->fill($attributes);
            $location->save();
            $this->audit->log($event, $location, $attributes);

            return $location;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can(self::PERMISSION), 403);
    }
}
