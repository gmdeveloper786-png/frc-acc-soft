<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\OperationalTransactionType;
use App\Enums\PaymentBehaviour;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperationalTransactionRequest;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FrcService;
use App\Models\Fund;
use App\Models\OperationalTransaction;
use App\Models\PaymentPurpose;
use App\Models\PurchaseBill;
use App\Models\StaffAdvance;
use App\Models\Vendor;
use App\Services\BranchAccess;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\StaffAdvanceService;
use App\Services\SplitPaymentDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationalTransactionController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $type = OperationalTransactionType::tryFrom((string) $request->route('type')) ?? abort(404);
        abort_unless($request->user()->can($type->permissionPrefix().'.view'), 403);
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $transactions = OperationalTransaction::query()->with(['branch', 'financialAccount', 'vendor', 'journalEntry'])
            ->where('type', $type)->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('party_name', 'like', '%'.$request->string('search').'%')->orWhere('reference_number', 'like', '%'.$request->string('search').'%')->orWhere('narration', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $canDirectPay = $type === OperationalTransactionType::Expense
            && $request->user()->canAny(['payments.create', 'petty_cash.create'])
            && PaymentPurpose::query()->where('is_active', true)->where('behaviour', PaymentBehaviour::DirectExpense)->exists();

        return view('accounting.transactions.index', compact('transactions', 'type', 'branches', 'canDirectPay'));
    }

    public function create(Request $request, BranchAccess $access): View
    {
        $type = OperationalTransactionType::tryFrom((string) $request->route('type')) ?? abort(404);
        abort_unless($request->user()->can($type->permissionPrefix().'.create'), 403);
        $data = $this->formData($request, $access);
        // A vendor payment opened from a bill (?bill=) or re-shown after a validation error starts on that bill,
        // but only if it is one of the posted bills this user may pay.
        $billId = (int) $request->old('purchase_bill_id', $request->query('bill'));
        $selectedBill = $type === OperationalTransactionType::VendorPayment ? $data['bills']->firstWhere('id', $billId) : null;
        $canDirectPay = $type === OperationalTransactionType::Expense
            && $request->user()->canAny(['payments.create', 'petty_cash.create'])
            && PaymentPurpose::query()->where('is_active', true)->where('behaviour', PaymentBehaviour::DirectExpense)->exists();

        return view('accounting.transactions.create', [...$data, 'type' => $type, 'selectedBill' => $selectedBill, 'canDirectPay' => $canDirectPay]);
    }

    public function store(StoreOperationalTransactionRequest $request, OperationalTransactionService $service): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('operational-finance', 'local');
        }
        $transaction = $service->create($data, $request->user());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Transaction draft created.');
    }

    public function show(Request $request, OperationalTransaction $transaction, BranchAccess $access, OperationalTransactionService $service): View
    {
        abort_unless($request->user()->can($transaction->type->permissionPrefix().'.view') && $access->canAccess($request->user(), $transaction->branch), 403);
        $transaction->load(['branch', 'financialAccount.account', 'contraAccount', 'payableAccount', 'vendor', 'service', 'fund', 'donationCategory', 'purchaseBill.payableAccount', 'payableObligation.sourceExpense', 'accruedPayableObligation', 'journalEntry', 'creator', 'approver']);
        $openPeriods = AccountingPeriod::query()->where('status', 'open')->orderBy('start_date')->get();
        $defaultReversalDate = today()->toDateString();
        $defaultReversalPeriodId = $openPeriods->first(fn (AccountingPeriod $period): bool => $defaultReversalDate >= $period->start_date->toDateString() && $defaultReversalDate <= $period->end_date->toDateString())?->id;

        $canApprove = $service->canApprove($transaction, $request->user());

        $canReject = $service->canReject($transaction, $request->user());
        $canPost = $service->canPost($transaction, $request->user());
        $hasActivePayableSettlements = $service->hasActivePayableSettlementsForSource($transaction);

        $splitWarning = app(SplitPaymentDetector::class)->analyze($transaction);
        // The staff advance a payment was raised for; the advance records this payment as its disbursement when it is posted.
        $advanceId = StaffAdvanceService::advanceIdFrom($transaction->source_reference);
        $linkedAdvance = $advanceId ? StaffAdvance::query()->with('branch')->find($advanceId, ['id', 'reference', 'branch_id']) : null;
        $canOpenAdvance = $linkedAdvance && $request->user()->can('staff_advances.view') && $access->canAccess($request->user(), $linkedAdvance->branch);

        return view('accounting.transactions.show', compact('transaction', 'openPeriods', 'defaultReversalDate', 'defaultReversalPeriodId', 'canApprove', 'canReject', 'canPost', 'splitWarning', 'hasActivePayableSettlements', 'linkedAdvance', 'canOpenAdvance'));
    }

    public function submit(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $service->submit($transaction, $request->user());

        return back()->with('status', 'Transaction submitted.');
    }

    public function approve(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $service->approve($transaction, $request->user());

        return back()->with('status', 'Transaction approved.');
    }

    public function reject(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($transaction, $data['reason'], $request->user());

        return back()->with('status', 'Transaction returned for correction.');
    }

    public function acknowledgment(Request $request, OperationalTransaction $transaction, BranchAccess $access): View
    {
        // A printable receipt exists only for money received: donations and cash or bank receipts, once posted.
        $permission = match ($transaction->type) {
            OperationalTransactionType::Donation => 'donations.view',
            OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt => 'receipts.view',
            default => null,
        };
        abort_unless($permission !== null && $transaction->status->value === 'posted', 404);
        abort_unless($request->user()->can($permission) && $access->canAccess($request->user(), $transaction->branch), 403);
        $transaction->load(['branch', 'financialAccount', 'donationCategory', 'journalEntry']);

        return view('accounting.transactions.donation-receipt', compact('transaction'));
    }

    public function disburse(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $service->disburse($transaction, $request->user());

        return back()->with('status', 'Actual disbursement confirmed.');
    }

    public function post(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $service->post($transaction, $request->user());

        return back()->with('status', 'Transaction posted to the Journal Engine.');
    }

    public function reverse(Request $request, OperationalTransaction $transaction, OperationalTransactionService $service): RedirectResponse
    {
        $data = $request->validate(['accounting_period_id' => ['required', 'exists:accounting_periods,id'], 'transaction_date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reverse($transaction, AccountingPeriod::query()->findOrFail($data['accounting_period_id']), $data['transaction_date'], $data['reason'], $request->user());

        return back()->with('status', 'Transaction reversed through Journal Engine.');
    }

    private function formData(Request $request, BranchAccess $access): array
    {
        $branchIds = $access->scope($request->user())->pluck('id');

        return [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'financialAccounts' => FinancialAccount::query()->with('branch')->usableIn($branchIds)->where('is_active', true)->orderBy('name')->get(),
            'ledgers' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'donationCategories' => DonationCategory::query()->with('fund')->where('is_active', true)->orderBy('name')->get(),
            'funds' => Fund::query()->active()->orderBy('code')->get(),
            'bills' => PurchaseBill::query()->with(['vendor', 'branch', 'postedPayments'])->whereIn('branch_id', $branchIds)->where('status', 'posted')->orderByDesc('invoice_date')->get(),
        ];
    }
}
