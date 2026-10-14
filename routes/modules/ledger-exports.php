<?php

// Routes for the ledger-exports module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\OperationalReportController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Facades\Route;

// Each export needs the screen's own view permission AND the export permission.
Route::get('/reports/general-ledger/export/{format}', [GeneralLedgerController::class, 'export'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:ledgers.view', 'permission:reports.export'])->name('reports.general-ledger.export');
Route::get('/reports/trial-balance/export/{format}', [TrialBalanceController::class, 'export'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:trial_balance.view', 'permission:reports.export'])->name('reports.trial-balance.export');

// The cash or bank book view permission depends on {kind}; the controller checks it.
Route::get('/reports/books/{kind}/export/{format}', [OperationalReportController::class, 'exportBook'])->whereIn('kind', ['cash', 'bank'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:cash_books.view|bank_books.view', 'permission:finance_reports.export'])->name('reports.books.export');
Route::get('/reports/registers/{kind}/export/{format}', [OperationalReportController::class, 'exportRegister'])->whereIn('kind', ['receipts', 'payments', 'expenses', 'donations'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:finance_reports.view', 'permission:finance_reports.export'])->name('reports.registers.export');
Route::get('/reports/vendor-ledger/export/{format}', [OperationalReportController::class, 'exportVendorLedger'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:finance_reports.view', 'permission:finance_reports.export'])->name('reports.vendor-ledger.export');
Route::get('/reports/payables/export/{format}', [OperationalReportController::class, 'exportPayables'])->whereIn('format', ReportExporter::FORMATS)
    ->middleware(['permission:finance_reports.view', 'permission:finance_reports.export'])->name('reports.payables.export');

// Audit trail: read-only. There are intentionally no create, update or delete routes.
Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view')->name('audit-logs.index');
Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog')->middleware('permission:audit_logs.view')->name('audit-logs.show');
