<?php

// Routes for payroll reports. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Reports\PayrollReportController;
use App\Services\Reports\Payroll\PayrollReportService;
use Illuminate\Support\Facades\Route;

// payroll.view opens the reports. Every amount additionally needs payroll.view_sensitive, which the report service
// enforces per report: amount-only reports are refused and mixed reports lose their amount columns.
Route::get('/payroll-reports', [PayrollReportController::class, 'index'])->middleware('permission:payroll.view')->name('payroll-reports.index');
Route::get('/payroll-reports/{report}/export/{format}', [PayrollReportController::class, 'export'])
    ->whereIn('report', array_keys(PayrollReportService::REPORTS))->whereIn('format', ['csv', 'xlsx', 'pdf'])
    ->middleware(['permission:payroll.view', 'permission:payroll.view_sensitive', 'permission:payroll_reports.export'])->name('payroll-reports.export');
Route::get('/payroll-reports/{report}', [PayrollReportController::class, 'show'])
    ->whereIn('report', array_keys(PayrollReportService::REPORTS))->middleware('permission:payroll.view')->name('payroll-reports.show');
