<?php

// Routes for the statements module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Reports\Statements\StatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('statements')->name('statements.')->group(function (): void {
    Route::get('/income-expenditure', [StatementController::class, 'incomeExpenditure'])->middleware('permission:financial_statements.view')->name('income-expenditure');
    Route::get('/balance-sheet', [StatementController::class, 'balanceSheet'])->middleware('permission:financial_statements.view')->name('balance-sheet');
    Route::get('/branch-income-expenditure', [StatementController::class, 'branchIncomeExpenditure'])->middleware('permission:financial_statements.view')->name('branch-income-expenditure');
    Route::get('/service-analysis', [StatementController::class, 'serviceAnalysis'])->middleware('permission:financial_statements.view')->name('service-analysis');
    Route::get('/{report}/export/{format}', [StatementController::class, 'export'])
        ->whereIn('report', ['income-expenditure', 'balance-sheet', 'branch-income-expenditure', 'service-analysis'])
        ->whereIn('format', ['csv', 'xlsx', 'pdf'])
        ->middleware(['permission:financial_statements.view', 'permission:financial_statements.export'])
        ->name('export');
});
