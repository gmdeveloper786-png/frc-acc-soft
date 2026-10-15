<?php

// Routes for petty cash imprest. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\PettyCash\ImprestFloatController;
use App\Http\Controllers\PettyCash\ImprestReplenishmentController;
use App\Http\Controllers\PettyCash\ImprestStatementController;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Facades\Route;

Route::prefix('imprest')->name('imprest.')->group(function (): void {
    Route::get('/', [ImprestFloatController::class, 'index'])->middleware('permission:imprest.view')->name('index');
    Route::get('/create', [ImprestFloatController::class, 'create'])->middleware('permission:imprest.manage')->name('create');
    Route::post('/', [ImprestFloatController::class, 'store'])->middleware('permission:imprest.manage')->name('store');
    Route::get('/{float}', [ImprestFloatController::class, 'show'])->middleware('permission:imprest.view')->name('show');
    Route::get('/{float}/edit', [ImprestFloatController::class, 'edit'])->middleware('permission:imprest.manage')->name('edit');
    Route::put('/{float}', [ImprestFloatController::class, 'update'])->middleware('permission:imprest.manage')->name('update');

    // Prepares a draft CV journal only; it is submitted, approved and posted through the journal workflow.
    Route::post('/{float}/replenishments', [ImprestReplenishmentController::class, 'store'])->middleware('permission:imprest.replenish')->name('replenishments.store');

    Route::get('/{float}/statement', [ImprestStatementController::class, 'show'])->middleware('permission:imprest.view')->name('statement');
    Route::get('/{float}/statement/export/{format}', [ImprestStatementController::class, 'export'])->whereIn('format', ReportExporter::FORMATS)
        ->middleware(['permission:imprest.view', 'permission:'.ImprestStatementController::EXPORT_PERMISSION])->name('statement.export');
});
