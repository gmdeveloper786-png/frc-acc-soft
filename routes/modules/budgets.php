<?php

// Routes for the budgets module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Budgets\BudgetController;
use App\Http\Controllers\Budgets\BudgetReportController;
use Illuminate\Support\Facades\Route;

Route::get('/budgets', [BudgetController::class, 'index'])->middleware('permission:budgets.view')->name('budgets.index');
Route::get('/budgets/create', [BudgetController::class, 'create'])->middleware('permission:budgets.create')->name('budgets.create');
Route::post('/budgets', [BudgetController::class, 'store'])->middleware('permission:budgets.create')->name('budgets.store');
// Called by the budget form for one row; returns the period amounts as JSON and saves nothing.
Route::post('/budgets/spread', [BudgetController::class, 'spread'])->middleware('permission:budgets.create|budgets.update')->name('budgets.spread');
Route::get('/budgets/{budget}', [BudgetController::class, 'show'])->middleware('permission:budgets.view')->name('budgets.show');
Route::get('/budgets/{budget}/edit', [BudgetController::class, 'edit'])->middleware('permission:budgets.update')->name('budgets.edit');
Route::put('/budgets/{budget}', [BudgetController::class, 'update'])->middleware('permission:budgets.update')->name('budgets.update');
Route::post('/budgets/{budget}/submit', [BudgetController::class, 'submit'])->middleware('permission:budgets.submit')->name('budgets.submit');
Route::post('/budgets/{budget}/approve', [BudgetController::class, 'approve'])->middleware('permission:budgets.approve')->name('budgets.approve');
Route::post('/budgets/{budget}/reject', [BudgetController::class, 'reject'])->middleware('permission:budgets.approve')->name('budgets.reject');
Route::post('/budgets/{budget}/revise', [BudgetController::class, 'revise'])->middleware('permission:budgets.revise')->name('budgets.revise');

Route::get('/budget-reports/versus-actual', [BudgetReportController::class, 'versusActual'])->middleware('permission:budget_reports.view')->name('budget-reports.versus-actual');
Route::get('/budget-reports/branch-summary', [BudgetReportController::class, 'branchSummary'])->middleware('permission:budget_reports.view')->name('budget-reports.branch-summary');
Route::get('/budget-reports/service-summary', [BudgetReportController::class, 'serviceSummary'])->middleware('permission:budget_reports.view')->name('budget-reports.service-summary');
Route::get('/budget-reports/utilisation', [BudgetReportController::class, 'utilisation'])->middleware('permission:budget_reports.view')->name('budget-reports.utilisation');

Route::get('/budget-reports/versus-actual/export/{format}', [BudgetReportController::class, 'exportVersusActual'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->middleware(['permission:budget_reports.view', 'permission:budget_reports.export'])->name('budget-reports.versus-actual.export');
Route::get('/budget-reports/branch-summary/export/{format}', [BudgetReportController::class, 'exportBranchSummary'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->middleware(['permission:budget_reports.view', 'permission:budget_reports.export'])->name('budget-reports.branch-summary.export');
Route::get('/budget-reports/service-summary/export/{format}', [BudgetReportController::class, 'exportServiceSummary'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->middleware(['permission:budget_reports.view', 'permission:budget_reports.export'])->name('budget-reports.service-summary.export');
