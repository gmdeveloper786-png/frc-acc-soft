<?php

// Routes for bank reconciliation. Loaded inside the authenticated, active-user group of routes/web.php.
// Controllers and services also check the user's branch for every bank account they touch.

use App\Http\Controllers\BankReconciliation\BankReconciliationController;
use App\Http\Controllers\BankReconciliation\BankReconciliationMatchController;
use App\Http\Controllers\BankReconciliation\BankStatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('bank-reconciliation')->group(function (): void {
    Route::get('/statements', [BankStatementController::class, 'index'])->middleware('permission:bank_reconciliation.view')->name('bank-statements.index');
    Route::post('/statements', [BankStatementController::class, 'store'])->middleware('permission:bank_reconciliation.prepare')->name('bank-statements.store');
    Route::get('/statements/{bankStatement}', [BankStatementController::class, 'show'])->whereNumber('bankStatement')->middleware('permission:bank_reconciliation.view')->name('bank-statements.show');
    Route::post('/statements/{bankStatement}/import', [BankStatementController::class, 'import'])->whereNumber('bankStatement')->middleware('permission:bank_reconciliation.prepare')->name('bank-statements.import');
    Route::delete('/statements/{bankStatement}', [BankStatementController::class, 'destroy'])->whereNumber('bankStatement')->middleware('permission:bank_reconciliation.prepare')->name('bank-statements.destroy');

    Route::get('/', [BankReconciliationController::class, 'index'])->middleware('permission:bank_reconciliation.view')->name('bank-reconciliations.index');
    Route::get('/create', [BankReconciliationController::class, 'create'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.create');
    Route::post('/', [BankReconciliationController::class, 'store'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.store');

    Route::prefix('/{bankReconciliation}')->whereNumber('bankReconciliation')->group(function (): void {
        Route::get('/', [BankReconciliationController::class, 'show'])->middleware('permission:bank_reconciliation.view')->name('bank-reconciliations.show');
        Route::get('/statement', [BankReconciliationController::class, 'statement'])->middleware('permission:bank_reconciliation.view')->name('bank-reconciliations.statement');
        Route::get('/export/{format}', [BankReconciliationController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->middleware(['permission:bank_reconciliation.view', 'permission:finance_reports.export'])->name('bank-reconciliations.export');
        Route::post('/cancel', [BankReconciliationController::class, 'cancel'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.cancel');
        Route::post('/submit', [BankReconciliationController::class, 'submit'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.submit');
        Route::post('/approve', [BankReconciliationController::class, 'approve'])->middleware('permission:bank_reconciliation.approve')->name('bank-reconciliations.approve');
        Route::post('/reject', [BankReconciliationController::class, 'reject'])->middleware('permission:bank_reconciliation.approve')->name('bank-reconciliations.reject');

        Route::post('/matches', [BankReconciliationMatchController::class, 'store'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.matches.store');
        Route::post('/auto-match', [BankReconciliationMatchController::class, 'autoMatch'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.auto-match');
        Route::post('/brought-forward', [BankReconciliationMatchController::class, 'broughtForward'])->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.brought-forward');
        Route::delete('/matches/{bankReconciliationMatch}', [BankReconciliationMatchController::class, 'destroy'])->whereNumber('bankReconciliationMatch')->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.matches.destroy');
        Route::post('/statement-lines/{bankStatementLine}/ignore', [BankReconciliationMatchController::class, 'ignore'])->whereNumber('bankStatementLine')->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.lines.ignore');
        Route::post('/statement-lines/{bankStatementLine}/restore', [BankReconciliationMatchController::class, 'restore'])->whereNumber('bankStatementLine')->middleware('permission:bank_reconciliation.prepare')->name('bank-reconciliations.lines.restore');
    });
});
