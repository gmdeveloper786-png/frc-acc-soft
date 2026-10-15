<?php

// Routes for funds and grants. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Funds\FundController;
use App\Http\Controllers\Funds\FundReportController;
use Illuminate\Support\Facades\Route;

Route::get('/funds', [FundController::class, 'index'])->middleware('permission:funds.view')->name('funds.index');
Route::get('/funds/create', [FundController::class, 'create'])->middleware('permission:funds.manage')->name('funds.create');
Route::post('/funds', [FundController::class, 'store'])->middleware('permission:funds.manage')->name('funds.store');
Route::post('/funds/workbook-codes', [FundController::class, 'loadWorkbookCodes'])->middleware('permission:funds.manage')->name('funds.workbook-codes');
Route::get('/funds/{fund}/edit', [FundController::class, 'edit'])->middleware('permission:funds.manage|grants.manage')->name('funds.edit');
Route::put('/funds/{fund}', [FundController::class, 'update'])->middleware('permission:funds.manage|grants.manage')->name('funds.update');

Route::prefix('fund-reports')->name('fund-reports.')->group(function (): void {
    Route::get('/income-expenditure', [FundReportController::class, 'incomeExpenditure'])->middleware('permission:fund_reports.view')->name('income-expenditure');
    Route::get('/balance-summary', [FundReportController::class, 'balanceSummary'])->middleware('permission:fund_reports.view')->name('balance-summary');
    Route::get('/grant-utilisation', [FundReportController::class, 'grantUtilisation'])->middleware('permission:fund_reports.view')->name('grant-utilisation');
    Route::get('/grants/{fund}/donor-report', [FundReportController::class, 'donorReport'])->middleware('permission:fund_reports.view')->name('donor-report');
    Route::get('/grants/{fund}/donor-report/export/{format}', [FundReportController::class, 'exportDonorReport'])
        ->whereIn('format', ['csv', 'xlsx', 'pdf'])
        ->middleware(['permission:fund_reports.view', 'permission:financial_statements.export'])
        ->name('donor-report.export');
    Route::get('/{report}/export/{format}', [FundReportController::class, 'export'])
        ->whereIn('report', ['income-expenditure', 'balance-summary', 'grant-utilisation'])
        ->whereIn('format', ['csv', 'xlsx', 'pdf'])
        ->middleware(['permission:fund_reports.view', 'permission:financial_statements.export'])
        ->name('export');
});
