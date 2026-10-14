<?php

// Routes for the cashflow module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Reports\CashFlow\CashFlowClassificationController;
use App\Http\Controllers\Reports\CashFlow\CashFlowStatementController;
use App\Http\Controllers\Reports\CashFlow\ReceiptsPaymentsController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/cash-flow', [CashFlowStatementController::class, 'show'])->middleware('permission:financial_statements.view')->name('cash-flow.statement');
Route::get('/reports/cash-flow/export/{format}', [CashFlowStatementController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])
    ->middleware(['permission:financial_statements.view', 'permission:financial_statements.export'])->name('cash-flow.statement.export');

Route::get('/reports/receipts-payments', [ReceiptsPaymentsController::class, 'show'])->middleware('permission:financial_statements.view')->name('cash-flow.receipts-payments');
Route::get('/reports/receipts-payments/export/{format}', [ReceiptsPaymentsController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])
    ->middleware(['permission:financial_statements.view', 'permission:financial_statements.export'])->name('cash-flow.receipts-payments.export');

Route::get('/reports/cash-flow/classification', [CashFlowClassificationController::class, 'index'])->middleware('permission:financial_statements.view|cash_flow.manage_classification')->name('cash-flow.classification.index');
Route::put('/reports/cash-flow/classification/{account}', [CashFlowClassificationController::class, 'update'])->middleware('permission:cash_flow.manage_classification')->name('cash-flow.classification.update');
