<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollComponentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePayrollComponentRequest;
use App\Models\PayrollComponent;
use App\Services\Payroll\PayrollComponentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PayrollComponentController extends Controller
{
    public function index(PayrollComponentService $service): View
    {
        return view('payroll.components.index', [
            'components' => PayrollComponent::query()->with('account')->orderBy('sort_order')->orderBy('name')->get(),
            ...$this->accounts($service),
        ]);
    }

    public function store(SavePayrollComponentRequest $request, PayrollComponentService $service): RedirectResponse
    {
        $component = $service->create($request->validated(), $request->user());

        return redirect()->route('payroll-components.index')->with('status', "Payroll component {$component->code} created.");
    }

    public function edit(PayrollComponent $payrollComponent, PayrollComponentService $service): View
    {
        return view('payroll.components.edit', ['component' => $payrollComponent, ...$this->accounts($service)]);
    }

    public function update(SavePayrollComponentRequest $request, PayrollComponent $payrollComponent, PayrollComponentService $service): RedirectResponse
    {
        $service->update($payrollComponent, $request->validated(), $request->user());

        return redirect()->route('payroll-components.index')->with('status', 'Payroll component updated.');
    }

    /** @return array<string, mixed> */
    private function accounts(PayrollComponentService $service): array
    {
        return [
            'earningAccounts' => $service->eligibleAccounts(PayrollComponentType::Earning),
            'deductionAccounts' => $service->eligibleAccounts(PayrollComponentType::Deduction),
        ];
    }
}
