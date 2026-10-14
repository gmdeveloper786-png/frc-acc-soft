<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateVoucherTypeRequest;
use App\Models\VoucherType;
use App\Services\VoucherTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VoucherTypeController extends Controller
{
    public function index(): View
    {
        $voucherTypes = VoucherType::query()->withCount('journals')->orderBy('code')->get();

        return view('accounting.voucher-types.index', compact('voucherTypes'));
    }

    public function edit(VoucherType $voucherType): View
    {
        return view('accounting.voucher-types.edit', compact('voucherType'));
    }

    public function update(UpdateVoucherTypeRequest $request, VoucherType $voucherType, VoucherTypeService $service): RedirectResponse
    {
        $service->update($voucherType, $request->validated(), $request->user());

        return redirect()->route('voucher-types.index')->with('status', 'Voucher type configuration updated.');
    }
}
