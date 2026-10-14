<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Models\Account;
use App\Models\FrcService;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\BranchAccess;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $orders = PurchaseOrder::query()->with(['vendor', 'branch'])->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when(PurchaseOrderStatus::tryFrom($request->string('status')->toString()), fn ($query, PurchaseOrderStatus $status) => $query->where('status', $status->value))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('po_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('po_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('po_number', 'like', '%'.$request->string('search').'%')->orWhere('reference', 'like', '%'.$request->string('search').'%')->orWhere('description', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('po_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('accounting.purchase-orders.index', ['orders' => $orders, 'branches' => $branches, 'vendors' => Vendor::query()->orderBy('name')->get()]);
    }

    public function create(Request $request, BranchAccess $access): View
    {
        return view('accounting.purchase-orders.create', $this->formData($request, $access));
    }

    public function store(SavePurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse
    {
        $order = $service->create($request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('status', "Purchase order {$order->po_number} saved as draft.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder, BranchAccess $access, PurchaseOrderService $service): View
    {
        $this->guardBranch($request, $purchaseOrder, $access);
        $purchaseOrder->load(['vendor', 'branch', 'creator', 'approver', 'lines.account', 'lines.service', 'bills.creator']);
        if ($request->user()->can('purchase_orders.view_history')) {
            $purchaseOrder->load('approvals.actor');
        }

        return view('accounting.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'canApprove' => $service->canApprove($purchaseOrder, $request->user()),
            'approvalTier' => $service->approvalTier($purchaseOrder->total_amount),
        ]);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder, BranchAccess $access): View
    {
        $this->guardBranch($request, $purchaseOrder, $access);
        abort_unless($purchaseOrder->status->isEditable(), 403, 'Only a draft or returned purchase order can be edited.');

        return view('accounting.purchase-orders.edit', [...$this->formData($request, $access), 'purchaseOrder' => $purchaseOrder->load('lines')]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $service->update($purchaseOrder, $request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('status', 'Purchase order draft updated.');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $service->submit($purchaseOrder, $request->user());

        return back()->with('status', 'Purchase order submitted for approval.');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);
        $service->approve($purchaseOrder, $request->user(), $data['comments'] ?? null);

        return back()->with('status', 'Purchase order approved. No accounting entry was posted.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($purchaseOrder, $data['reason'], $request->user());

        return back()->with('status', 'Purchase order returned for correction.');
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $service->issue($purchaseOrder, $request->user());

        return back()->with('status', 'Purchase order marked as issued to the vendor.');
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $service->close($purchaseOrder, $data['reason'] ?? null, $request->user());

        return back()->with('status', 'Purchase order closed.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->cancel($purchaseOrder, $data['reason'], $request->user());

        return back()->with('status', 'Purchase order cancelled.');
    }

    private function guardBranch(Request $request, PurchaseOrder $order, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $order->branch), 403);
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, BranchAccess $access): array
    {
        return [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(),
            'ledgers' => Account::query()->whereIn('account_type', ['expense', 'asset'])->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
        ];
    }
}
