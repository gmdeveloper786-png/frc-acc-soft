<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnifiedReceiptRequest;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Models\FrcService;
use App\Models\Fund;
use App\Models\OperationalTransaction;
use App\Models\Vendor;
use App\Services\BranchAccess;
use App\Services\OperationalTransactionService;
use App\Services\PettyCash\ImprestLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unified receipt entry, Stage 1: consolidated Cash and Bank receipt interfaces
 * reusing the established OperationalTransactionService and CR/BR voucher sequences.
 */
class UnifiedReceiptController extends Controller
{
    /** @var list<OperationalTransactionType> */
    public const RECEIPT_TYPES = [
        OperationalTransactionType::CashReceipt,
        OperationalTransactionType::BankReceipt,
    ];

    public function index(Request $request, BranchAccess $access): View
    {
        $user = $request->user();
        abort_unless($user->can('receipts.view'), 403);

        $branches = $access->scope($user)->orderBy('name')->get();
        $branchIds = $branches->pluck('id');

        $receiptTypeFilter = match ((string) $request->string('type')) {
            'cash', 'cash_receipt' => [OperationalTransactionType::CashReceipt->value],
            'bank', 'bank_receipt' => [OperationalTransactionType::BankReceipt->value],
            default => [OperationalTransactionType::CashReceipt->value, OperationalTransactionType::BankReceipt->value],
        };

        $search = trim((string) $request->string('search'));

        $receipts = OperationalTransaction::query()
            ->with(['branch', 'financialAccount', 'contraAccount', 'vendor', 'journalEntry'])
            ->whereIn('branch_id', $branchIds)
            ->whereIn('type', $receiptTypeFilter)
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when(OperationalTransactionStatus::tryFrom((string) $request->string('status')), fn ($q, $status) => $q->where('status', $status->value))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('reference_number', 'like', "%{$search}%")
                ->orWhere('party_name', 'like', "%{$search}%")
                ->orWhere('narration', 'like', "%{$search}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"))
                ->orWhereHas('journalEntry', fn ($j) => $j->where('voucher_number', 'like', "%{$search}%"))
            ))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.receipts.index', [
            'receipts' => $receipts,
            'branches' => $branches,
            'canCreate' => $user->can('receipts.create'),
        ]);
    }

    public function create(Request $request, BranchAccess $access, ImprestLedgerService $imprest): View
    {
        $user = $request->user();
        abort_unless($user->can('receipts.create'), 403);

        $branches = $access->scope($user)->whereIn('status', ['publish', 'active'])->orderBy('name')->get();
        $branchIds = $branches->pluck('id');

        // Exclude cash accounts holding active imprest floats as receipts cannot fund petty cash floats.
        $financialAccounts = FinancialAccount::query()
            ->with('branch')
            ->usableIn($branchIds)
            ->whereIn('type', ['cash', 'bank'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (FinancialAccount $account): bool => $account->type === 'cash' && $imprest->activeFloatFor($account) !== null)
            ->values();

        $ledgers = Account::query()
            ->where('level', 'ledger')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $vendors = Vendor::query()->where('is_active', true)->orderBy('name')->get();
        $services = FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get();
        $funds = Fund::query()->active()->orderBy('code')->get();

        $selectedMethod = (string) old('method', old('type', $request->query('method', $request->query('mode', 'cash'))));
        if (! in_array($selectedMethod, ['cash', 'bank'], true)) {
            $selectedMethod = $selectedMethod === OperationalTransactionType::BankReceipt->value ? 'bank' : 'cash';
        }

        return view('accounting.receipts.create', [
            'branches' => $branches,
            'financialAccounts' => $financialAccounts,
            'ledgers' => $ledgers,
            'vendors' => $vendors,
            'services' => $services,
            'funds' => $funds,
            'selectedMethod' => $selectedMethod,
        ]);
    }

    public function store(StoreUnifiedReceiptRequest $request, OperationalTransactionService $service): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path'] = $file->store('operational-finance', 'local');
            $data['attachment_original_name'] = str($file->getClientOriginalName())->limit(250, '')->toString();
        }

        $transaction = $service->create($data, $request->user());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Receipt draft created successfully. Awaiting posting by an authorized user.');
    }
}
