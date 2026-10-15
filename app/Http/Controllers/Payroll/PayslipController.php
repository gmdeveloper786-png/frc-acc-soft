<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayslipService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable payslips. Access, branch scope and the run status are enforced by
 * PayslipService; there is no export, only the browser's print.
 */
class PayslipController extends Controller
{
    public function show(Request $request, PayrollRun $payrollRun, PayrollRunLine $line, PayslipService $payslips): View
    {
        abort_unless($line->payroll_run_id === $payrollRun->id, 404);

        return view('payroll.payslips.print', $payslips->build($payrollRun, $request->user(), $line));
    }

    public function printAll(Request $request, PayrollRun $payrollRun, PayslipService $payslips): View
    {
        return view('payroll.payslips.print', $payslips->build($payrollRun, $request->user()));
    }
}
