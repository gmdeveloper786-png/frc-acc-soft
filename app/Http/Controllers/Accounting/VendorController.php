<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorRequest;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        $vendors = Vendor::query()->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status') === 'active'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('vendor_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('accounting.vendors.index', compact('vendors'));
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('vendors.create'), 403);

        return view('accounting.vendors.form');
    }

    public function store(StoreVendorRequest $request, AuditLogger $audit): RedirectResponse
    {
        $vendor = Vendor::query()->create([...$request->validated(), 'created_by' => $request->user()->id]);
        $audit->log('vendors.created', $vendor, ['vendor_code' => $vendor->vendor_code, 'name' => $vendor->name]);

        return redirect()->route('vendors.show', $vendor)->with('status', 'Vendor created.');
    }

    public function show(Vendor $vendor): View
    {
        $vendor->loadCount('purchaseBills');

        return view('accounting.vendors.show', compact('vendor'));
    }

    public function edit(Request $request, Vendor $vendor): View
    {
        abort_unless($request->user()->can('vendors.update'), 403);

        return view('accounting.vendors.form', compact('vendor'));
    }

    public function update(StoreVendorRequest $request, Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        if (blank($data['payment_details'] ?? null)) {
            unset($data['payment_details']);
        }
        $vendor->update($data);
        $audit->log('vendors.updated', $vendor, ['vendor_code' => $vendor->vendor_code, 'name' => $vendor->name]);

        return redirect()->route('vendors.show', $vendor)->with('status', 'Vendor updated.');
    }

    public function toggle(Request $request, Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('vendors.deactivate'), 403);
        $vendor->update(['is_active' => ! $vendor->is_active]);
        $audit->log('vendors.status_changed', $vendor, ['is_active' => $vendor->is_active]);

        return back()->with('status', 'Vendor status updated.');
    }
}
