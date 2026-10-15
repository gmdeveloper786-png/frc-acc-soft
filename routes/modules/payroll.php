<?php

// Routes for the payroll module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\PayrollComponentController;
use App\Http\Controllers\Payroll\PayrollImportController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\StaffAdvanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('payroll')->group(function (): void {
    // Employee master. Amounts and bank details additionally need payroll.view_sensitive (checked server-side).
    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view')->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->middleware('permission:employees.manage')->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.manage')->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view')->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware('permission:employees.manage')->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.manage')->name('employees.update');
    Route::put('/employees/{employee}/salary', [EmployeeController::class, 'updateSalary'])->middleware(['permission:employees.manage', 'permission:payroll.view_sensitive'])->name('employees.salary.update');

    Route::get('/components', [PayrollComponentController::class, 'index'])->middleware('permission:payroll_components.manage')->name('payroll-components.index');
    Route::post('/components', [PayrollComponentController::class, 'store'])->middleware('permission:payroll_components.manage')->name('payroll-components.store');
    Route::get('/components/{payrollComponent}/edit', [PayrollComponentController::class, 'edit'])->middleware('permission:payroll_components.manage')->name('payroll-components.edit');
    Route::put('/components/{payrollComponent}', [PayrollComponentController::class, 'update'])->middleware('permission:payroll_components.manage')->name('payroll-components.update');

    Route::get('/runs', [PayrollRunController::class, 'index'])->middleware('permission:payroll.view')->name('payroll-runs.index');
    Route::get('/runs/create', [PayrollRunController::class, 'create'])->middleware('permission:payroll.manage')->name('payroll-runs.create');
    Route::post('/runs', [PayrollRunController::class, 'store'])->middleware('permission:payroll.manage')->name('payroll-runs.store');
    Route::get('/runs/{payrollRun}', [PayrollRunController::class, 'show'])->middleware('permission:payroll.view')->name('payroll-runs.show');
    Route::get('/runs/{payrollRun}/register', [PayrollRunController::class, 'register'])->middleware(['permission:payroll.view', 'permission:payroll.view_sensitive'])->name('payroll-runs.register');
    // Payslips: approved, posted or paid runs only; amounts need payroll.view_sensitive (also enforced by PayslipService).
    Route::get('/runs/{payrollRun}/payslips', [PayslipController::class, 'printAll'])->middleware(['permission:payroll.view', 'permission:payroll.view_sensitive'])->name('payroll-runs.payslips');
    Route::get('/runs/{payrollRun}/lines/{line}/payslip', [PayslipController::class, 'show'])->middleware(['permission:payroll.view', 'permission:payroll.view_sensitive'])->name('payroll-runs.payslip');
    Route::post('/runs/{payrollRun}/advance-recoveries', [PayrollRunController::class, 'applyAdvanceRecoveries'])->middleware(['permission:payroll.manage', 'permission:payroll.view_sensitive'])->name('payroll-runs.advance-recoveries');
    Route::post('/runs/{payrollRun}/lines', [PayrollRunController::class, 'addLine'])->middleware('permission:payroll.manage')->name('payroll-runs.lines.store');
    Route::get('/runs/{payrollRun}/lines/{line}/edit', [PayrollRunController::class, 'editLine'])->middleware(['permission:payroll.manage', 'permission:payroll.view_sensitive'])->name('payroll-runs.lines.edit');
    Route::put('/runs/{payrollRun}/lines/{line}', [PayrollRunController::class, 'updateLine'])->middleware(['permission:payroll.manage', 'permission:payroll.view_sensitive'])->name('payroll-runs.lines.update');
    Route::delete('/runs/{payrollRun}/lines/{line}', [PayrollRunController::class, 'removeLine'])->middleware('permission:payroll.manage')->name('payroll-runs.lines.destroy');
    Route::post('/runs/{payrollRun}/validate', [PayrollRunController::class, 'validateRun'])->middleware('permission:payroll.manage')->name('payroll-runs.validate');
    Route::post('/runs/{payrollRun}/revert', [PayrollRunController::class, 'revert'])->middleware('permission:payroll.manage')->name('payroll-runs.revert');
    Route::post('/runs/{payrollRun}/discard', [PayrollRunController::class, 'discard'])->middleware('permission:payroll.manage')->name('payroll-runs.discard');
    Route::post('/runs/{payrollRun}/submit', [PayrollRunController::class, 'submit'])->middleware('permission:payroll.submit')->name('payroll-runs.submit');
    Route::post('/runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])->middleware('permission:payroll.approve')->name('payroll-runs.approve');
    Route::post('/runs/{payrollRun}/reject', [PayrollRunController::class, 'reject'])->middleware('permission:payroll.approve')->name('payroll-runs.reject');
    Route::post('/runs/{payrollRun}/post', [PayrollRunController::class, 'post'])->middleware(['permission:payroll.post', 'permission:journals.post'])->name('payroll-runs.post');
    Route::post('/runs/{payrollRun}/pay', [PayrollRunController::class, 'pay'])->middleware('permission:payroll.pay')->name('payroll-runs.pay');
    Route::post('/runs/{payrollRun}/reverse', [PayrollRunController::class, 'reverse'])->middleware('permission:payroll.post')->name('payroll-runs.reverse');

    // Staff advances and loans: a register and recovery schedule. Nothing here posts; amounts need payroll.view_sensitive.
    Route::get('/advances', [StaffAdvanceController::class, 'index'])->middleware('permission:staff_advances.view')->name('staff-advances.index');
    Route::get('/advances/create', [StaffAdvanceController::class, 'create'])->middleware(['permission:staff_advances.manage', 'permission:payroll.view_sensitive'])->name('staff-advances.create');
    Route::post('/advances', [StaffAdvanceController::class, 'store'])->middleware(['permission:staff_advances.manage', 'permission:payroll.view_sensitive'])->name('staff-advances.store');
    Route::get('/advances/{staffAdvance}', [StaffAdvanceController::class, 'show'])->middleware('permission:staff_advances.view')->name('staff-advances.show');
    Route::get('/advances/{staffAdvance}/edit', [StaffAdvanceController::class, 'edit'])->middleware(['permission:staff_advances.manage', 'permission:payroll.view_sensitive'])->name('staff-advances.edit');
    Route::put('/advances/{staffAdvance}', [StaffAdvanceController::class, 'update'])->middleware(['permission:staff_advances.manage', 'permission:payroll.view_sensitive'])->name('staff-advances.update');
    Route::post('/advances/{staffAdvance}/submit', [StaffAdvanceController::class, 'submit'])->middleware('permission:staff_advances.manage')->name('staff-advances.submit');
    Route::post('/advances/{staffAdvance}/approve', [StaffAdvanceController::class, 'approve'])->middleware(['permission:staff_advances.approve', 'permission:payroll.view_sensitive'])->name('staff-advances.approve');
    Route::post('/advances/{staffAdvance}/reject', [StaffAdvanceController::class, 'reject'])->middleware('permission:staff_advances.approve')->name('staff-advances.reject');
    Route::post('/advances/{staffAdvance}/cancel', [StaffAdvanceController::class, 'cancel'])->middleware('permission:staff_advances.manage|staff_advances.approve')->name('staff-advances.cancel');
    Route::post('/advances/{staffAdvance}/disbursement', [StaffAdvanceController::class, 'linkDisbursement'])->middleware(['permission:staff_advances.manage', 'permission:payroll.view_sensitive'])->name('staff-advances.disbursement');

    Route::get('/imports', [PayrollImportController::class, 'index'])->middleware('permission:payroll.import')->name('payroll-imports.index');
    Route::post('/imports', [PayrollImportController::class, 'store'])->middleware('permission:payroll.import')->name('payroll-imports.store');
    Route::get('/imports/{payrollImport}', [PayrollImportController::class, 'show'])->middleware('permission:payroll.import')->name('payroll-imports.show');
    Route::post('/imports/{payrollImport}/mapping', [PayrollImportController::class, 'mapping'])->middleware('permission:payroll.import')->name('payroll-imports.mapping');
    Route::get('/imports/{payrollImport}/report', [PayrollImportController::class, 'report'])->middleware('permission:payroll.import')->name('payroll-imports.report');
    Route::get('/imports/{payrollImport}/report.csv', [PayrollImportController::class, 'export'])->middleware('permission:payroll.import')->name('payroll-imports.export');
    Route::post('/imports/{payrollImport}/rows', [PayrollImportController::class, 'exclude'])->middleware('permission:payroll.import')->name('payroll-imports.exclude');
    Route::post('/imports/{payrollImport}/confirm', [PayrollImportController::class, 'confirm'])->middleware('permission:payroll.import')->name('payroll-imports.confirm');
    Route::post('/imports/{payrollImport}/cancel', [PayrollImportController::class, 'cancel'])->middleware('permission:payroll.import')->name('payroll-imports.cancel');
});
