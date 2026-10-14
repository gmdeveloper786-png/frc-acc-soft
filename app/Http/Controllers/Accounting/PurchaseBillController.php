<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountMappingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseBillRequest;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\FrcService;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\BranchAccess;
use App\Services\PurchaseBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseBillController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $bills = PurchaseBill::query()->with(['vendor', 'branch', 'journalEntry', 'postedPayments'])->whereIn('branch_id', $access->scope($request->user())->select('id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->orderByDesc('invoice_date')->paginate(20)->withQueryString();

        return view('accounting.purchase-bills.index', ['bills' => $bills, 'vendors' => Vendor::query()->orderBy('name')->get()]);
    }

    public function create(Request $request, BranchAccess $access): View
    {
        abort_unless($request->user()->can('purchase_bills.create'), 403);
        $order = null;
        if ($request->filled('purchase_order')) {
            $order = PurchaseOrder::query()->with(['lines', 'vendor', 'branch'])->findOrFail($request->integer('purchase_order'));
            abort_unless($access->canAccess($request->user(), $order->branch), 403);
            abort_unless($order->status->acceptsBills(), 403, 'Only an approved or issued purchase order can be billed.');
        }

        return view('accounting.purchase-bills.create', [
            'order' => $order,
            'payableMapped' => AccountMapping::query()->whereIn('mapping_key', [AccountMappingKey::AccountsPayable->value, AccountMappingKey::PurchaseBillControl->value])->exists(),
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(),
            'ledgers' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
        ]);
    }

    public function store(StorePurchaseBillRequest $request, PurchaseBillService $service): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('purchase-bills', 'local');
        }
        $bill = $service->create($data, $request->user());

        return redirect()->route('purchase-bills.show', $bill)->with('status', 'Purchase bill draft created.');
    }

    public function show(Request $request, PurchaseBill $purchaseBill, BranchAccess $access): View
    {
        abort_unless($access->canAccess($request->user(), $purchaseBill->branch), 403);
        $purchaseBill->load(['vendor', 'branch', 'payableAccount', 'purchaseOrder', 'lines.account', 'lines.service', 'payments.journalEntry', 'journalEntry', 'creator']);
        $openPeriods = AccountingPeriod::query()->where('status', 'open')->orderBy('start_date')->get();

        return view('accounting.purchase-bills.show', compact('purchaseBill', 'openPeriods'));
    }

    public function submit(Request $request, PurchaseBill $purchaseBill, PurchaseBillService $service): RedirectResponse
    {
        $service->submit($purchaseBill, $request->user());

        return back()->with('status', 'Bill submitted.');
    }

    public function approve(Request $request, PurchaseBill $purchaseBill, PurchaseBillService $service): RedirectResponse
    {
        $service->approve($purchaseBill, $request->user());

        return back()->with('status', 'Bill approved.');
    }

    public function reject(Request $request, PurchaseBill $purchaseBill, PurchaseBillService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($purchaseBill, $data['reason'], $request->user());

        return back()->with('status', 'Bill returned for correction.');
    }

    public function post(Request $request, PurchaseBill $purchaseBill, PurchaseBillService $service): RedirectResponse
    {
        $service->post($purchaseBill, $request->user());

        return back()->with('status', 'Bill posted.');
    }

    public function reverse(Request $request, PurchaseBill $purchaseBill, PurchaseBillService $service): RedirectResponse
    {
        $data = $request->validate(['accounting_period_id' => ['required', 'exists:accounting_periods,id'], 'transaction_date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reverse($purchaseBill, AccountingPeriod::query()->findOrFail($data['accounting_period_id']), $data['transaction_date'], $data['reason'], $request->user());

        return back()->with('status', 'Bill reversed.');
    }
}
