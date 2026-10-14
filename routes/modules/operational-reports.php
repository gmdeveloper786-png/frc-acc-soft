<?php

// Routes for the operational-reports module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Reports\AssetReportController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Services\Reports\Operational\AssetReportService;
use App\Services\Reports\Operational\InventoryReportService;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Facades\Route;

Route::prefix('reports/assets')->name('reports.assets.')->group(function (): void {
    Route::get('/', [AssetReportController::class, 'index'])->middleware('permission:fixed_assets.view|depreciation.view')->name('index');

    // Each report is its own route so it carries its own view permission; an export additionally needs the export permission.
    foreach (AssetReportService::REPORTS as $report => $definition) {
        Route::get('/'.$report, [AssetReportController::class, 'show'])->defaults('report', $report)
            ->middleware('permission:'.$definition['permission'])->name($report);
        Route::get('/'.$report.'/export/{format}', [AssetReportController::class, 'export'])->defaults('report', $report)
            ->whereIn('format', ReportExporter::FORMATS)
            ->middleware(['permission:'.$definition['permission'], 'permission:'.AssetReportService::EXPORT_PERMISSION])->name($report.'.export');
    }
});

Route::prefix('reports/inventory')->name('reports.inventory.')->group(function (): void {
    Route::get('/', [InventoryReportController::class, 'index'])->middleware('permission:'.InventoryReportService::PERMISSION)->name('index');

    foreach (array_keys(InventoryReportService::REPORTS) as $report) {
        Route::get('/'.$report, [InventoryReportController::class, 'show'])->defaults('report', $report)
            ->middleware('permission:'.InventoryReportService::PERMISSION)->name($report);
        Route::get('/'.$report.'/export/{format}', [InventoryReportController::class, 'export'])->defaults('report', $report)
            ->whereIn('format', ReportExporter::FORMATS)
            ->middleware(['permission:'.InventoryReportService::PERMISSION, 'permission:'.InventoryReportService::EXPORT_PERMISSION])->name($report.'.export');
    }
});
