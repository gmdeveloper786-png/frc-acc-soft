<?php

// Routes for the inventory module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\InventoryCategoryController;
use App\Http\Controllers\Inventory\InventoryDashboardController;
use App\Http\Controllers\Inventory\InventoryItemController;
use App\Http\Controllers\Inventory\InventoryLocationController;
use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Inventory\InventoryStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->name('inventory.')->group(function (): void {
    Route::get('/', InventoryDashboardController::class)->middleware('permission:inventory.view')->name('dashboard');

    Route::get('/items', [InventoryItemController::class, 'index'])->middleware('permission:inventory.view')->name('items.index');
    Route::get('/items/create', [InventoryItemController::class, 'create'])->middleware('permission:inventory_items.manage')->name('items.create');
    Route::post('/items', [InventoryItemController::class, 'store'])->middleware('permission:inventory_items.manage')->name('items.store');
    Route::get('/items/{inventoryItem}/edit', [InventoryItemController::class, 'edit'])->middleware('permission:inventory_items.manage')->name('items.edit');
    Route::put('/items/{inventoryItem}', [InventoryItemController::class, 'update'])->middleware('permission:inventory_items.manage')->name('items.update');

    Route::get('/categories', [InventoryCategoryController::class, 'index'])->middleware('permission:inventory.view')->name('categories.index');
    Route::post('/categories', [InventoryCategoryController::class, 'store'])->middleware('permission:inventory_items.manage')->name('categories.store');
    Route::put('/categories/{inventoryCategory}', [InventoryCategoryController::class, 'update'])->middleware('permission:inventory_items.manage')->name('categories.update');

    Route::get('/locations', [InventoryLocationController::class, 'index'])->middleware('permission:inventory.view')->name('locations.index');
    Route::post('/locations', [InventoryLocationController::class, 'store'])->middleware('permission:inventory_items.manage')->name('locations.store');
    Route::put('/locations/{inventoryLocation}', [InventoryLocationController::class, 'update'])->middleware('permission:inventory_items.manage')->name('locations.update');

    Route::get('/balances', [InventoryStockController::class, 'balances'])->middleware('permission:inventory.view')->name('balances.index');
    Route::get('/low-stock', [InventoryStockController::class, 'lowStock'])->middleware('permission:inventory.view')->name('low-stock.index');
    Route::get('/movements', [InventoryMovementController::class, 'index'])->middleware('permission:inventory.view')->name('movements.index');

    // The movement kind is fixed by the route, never read from the request body.
    foreach (['receive' => ['receipt', 'inventory.receive'], 'issue' => ['issue', 'inventory.issue'], 'return' => ['return', 'inventory.return'], 'adjust' => ['adjustment', 'inventory.adjust']] as $action => [$kind, $permission]) {
        Route::get("/{$action}", [InventoryMovementController::class, 'create'])->defaults('kind', $kind)->middleware("permission:{$permission}")->name("{$action}.create");
        Route::post("/{$action}", [InventoryMovementController::class, 'store'])->defaults('kind', $kind)->middleware("permission:{$permission}")->name("{$action}.store");
    }

    Route::get('/transfer', [InventoryMovementController::class, 'createTransfer'])->middleware('permission:inventory.transfer')->name('transfer.create');
    Route::post('/transfer', [InventoryMovementController::class, 'storeTransfer'])->middleware('permission:inventory.transfer')->name('transfer.store');

    Route::get('/adjustments', [InventoryAdjustmentController::class, 'index'])->middleware('permission:inventory.view')->name('adjustments.index');
    Route::post('/adjustments/{inventoryMovement}/approve', [InventoryAdjustmentController::class, 'approve'])->middleware('permission:inventory.approve_adjustment')->name('adjustments.approve');
    Route::post('/adjustments/{inventoryMovement}/reject', [InventoryAdjustmentController::class, 'reject'])->middleware('permission:inventory.approve_adjustment')->name('adjustments.reject');
});
