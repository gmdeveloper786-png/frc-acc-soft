<?php

// Routes for the FRC financial integration. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\FrcIntegration\FrcIntegrationController;
use App\Http\Controllers\FrcIntegration\FrcPaymentController;
use App\Http\Controllers\FrcIntegration\FrcReconciliationController;
use App\Http\Controllers\FrcIntegration\FrcSyncHistoryController;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Facades\Route;

Route::prefix('frc-integration')->name('frc-integration.')->group(function (): void {
    Route::get('/', [FrcIntegrationController::class, 'index'])->middleware('permission:frc_integration.view')->name('index');
    Route::post('/sync', [FrcIntegrationController::class, 'sync'])->middleware(['permission:frc_integration.sync', 'throttle:3,1'])->name('sync');
    Route::post('/connection-check', [FrcIntegrationController::class, 'check'])->middleware(['permission:frc_integration.sync', 'throttle:6,1'])->name('check');

    Route::get('/payments', [FrcPaymentController::class, 'index'])->middleware('permission:frc_integration.view')->name('payments.index');
    Route::get('/payments/unmapped', [FrcPaymentController::class, 'unmapped'])->middleware('permission:frc_integration.view')->name('payments.unmapped');
    Route::get('/payments/{payment}', [FrcPaymentController::class, 'show'])->whereNumber('payment')->middleware('permission:frc_integration.view')->name('payments.show');

    Route::get('/sync-runs', [FrcSyncHistoryController::class, 'runs'])->middleware('permission:frc_integration.view')->name('runs.index');
    Route::get('/exceptions', [FrcSyncHistoryController::class, 'exceptions'])->middleware('permission:frc_integration.view')->name('exceptions.index');
    Route::post('/exceptions/{exception}/resolve', [FrcSyncHistoryController::class, 'resolve'])->whereNumber('exception')->middleware(['permission:frc_integration.view', 'permission:frc_integration.sync'])->name('exceptions.resolve');

    Route::get('/reconciliation', [FrcReconciliationController::class, 'show'])->middleware('permission:frc_integration.view')->name('reconciliation');
    // The export needs the screen's view permission AND the statements export permission.
    Route::get('/reconciliation/export/{format}', [FrcReconciliationController::class, 'export'])->whereIn('format', ReportExporter::FORMATS)
        ->middleware(['permission:frc_integration.view', 'permission:financial_statements.export'])->name('reconciliation.export');
});
