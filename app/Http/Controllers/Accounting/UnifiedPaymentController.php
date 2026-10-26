<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\EmploymentStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PaymentBehaviour;
use App\Enums\PayrollRunStatus;
use App\Enums\StaffAdvanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnifiedPaymentRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FrcService;
use App\Models\Fund;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\PayrollRun;
use App\Models\PurchaseBill;
use App\Models\StaffAdvance;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\Payments\PaymentPurposeRegistry;
use App\Services\Payments\UnifiedPaymentService;
use App\Services\Payroll\PayrollRunService;
use App\Services\PettyCash\ImprestLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Unified payment entry, Phase 1: bank payments through the existing workflows. */
class UnifiedPaymentController extends Controller
{
    /** Outgoing payment types listed together; receipts, donations and accrued expenses keep their own registers. */
    public const LISTED_TYPES = [OperationalTransactionType::BankPayment, OperationalTransactionType::CashPayment, OperationalTransactionType::VendorPayment, OperationalTransactionType::PettyCash];

    /**
     * Read-only history of every outgoing payment, new (unified) and legacy, of the
     * branches this user may see and the payment types they may view. Each row is
     * the transaction itself, listed under its own branch (shared bank accounts too).
     */
    public function index(Request $request, BranchAccess $access): View
    {
        $user = $request->user();
        $types = collect(self::LISTED_TYPES)->filter(fn (OperationalTransactionType $type) => $user->can($type->permissionPrefix().'.view'))->values();
        $includeLegacyExpenses = $user->can('payments.view') || $user->can('expenses.view');
        abort_if($types->isEmpty() && ! $includeLegacyExpenses, 403);
        $branches = $access->scope($user)->orderBy('name')->get();
        $search = trim((string) $request->string('search'));
        $source = (string) $request->string('source');

        $payments = OperationalTransaction::query()->with(['branch', 'financialAccount', 'vendor', 'journalEntry', 'paymentPurpose'])
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where(function ($query) use ($types, $includeLegacyExpenses) {
                if ($types->isNotEmpty()) {
                    $query->whereIn('type', $types->map->value->all());
                } else {
                    $query->whereRaw('0 = 1');
                }
                if ($includeLegacyExpenses) {
                    $query->orWhere(function ($q) {
                        $q->where('type', OperationalTransactionType::Expense->value)
                            ->where('settlement_type', 'paid');
                    });
                }
            })
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($source === UnifiedPaymentService::SOURCE_PETTY_CASH, fn ($q) => $q->where('type', OperationalTransactionType::PettyCash->value))
            ->when(in_array($source, [UnifiedPaymentService::SOURCE_BANK, UnifiedPaymentService::SOURCE_CASH], true), fn ($q) => $q->where('type', '!=', OperationalTransactionType::PettyCash->value)
                ->whereHas('financialAccount', fn ($account) => $account->where('type', $source)))
            ->when(OperationalTransactionStatus::tryFrom((string) $request->string('status')), fn ($q, $status) => $q->where('status', $status->value))
            ->when($request->filled('purpose_id'), fn ($q) => $q->where('payment_purpose_id', $request->integer('purpose_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhere('party_name', 'like', "%{$search}%")
                ->orWhere('payee_snapshot', 'like', "%{$search}%")->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$search}%"))
                ->orWhereHas('journalEntry', fn ($journal) => $journal->where('voucher_number', 'like', "%{$search}%"))))
            ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('accounting.payments.index', [
            'payments' => $payments,
            'branches' => $branches,
            'purposes' => PaymentPurpose::query()->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->canAny(['payments.create', 'vendor_payments.create', 'payroll.pay', 'petty_cash.create']),
        ]);
    }

    public function create(Request $request, BranchAccess $access, PaymentPurposeRegistry $registry, PayrollRunService $payroll, ImprestLedgerService $imprest): View
    {
        $user = $request->user();
        // Each purpose carries the sources this user may pay it from (behaviour x source x permission).
        $purposes = collect(UnifiedPaymentService::SOURCES)
            ->flatMap(fn (string $source) => $registry->availableTo($user, $source)->map(fn ($purpose) => [$purpose, $source]))
            ->groupBy(fn (array $pair) => $pair[0]->id)
            ->map(function ($pairs) {
                $purpose = $pairs->first()[0];
                $purpose->setAttribute('allowed_sources', $pairs->map(fn (array $pair) => $pair[1])->values()->all());

                return $purpose;
            })->sortBy([['sort_order', 'asc'], ['name', 'asc']])->values();
        abort_if($purposes->isEmpty(), 403);
        $sources = collect(UnifiedPaymentService::SOURCES)->filter(fn (string $source) => $purposes->contains(fn ($purpose) => in_array($source, $purpose->allowed_sources, true)))->values();
        $codes = $purposes->map(fn ($purpose) => $purpose->behaviour->value)->unique()->all();
        $branchIds = $access->scope($user)->pluck('id');
        $positive = fn (string $amount): bool => AccountingDecimal::of($amount)->isPositive();
        $obligations = in_array('accrued_payable', $codes, true)
            ? PayableObligation::query()->with('vendor')->whereIn('branch_id', $branchIds)->orderByDesc('obligation_date')->get()->filter(fn (PayableObligation $obligation): bool => $positive($obligation->outstandingAmount()))->values()
            : collect();
        $advances = in_array('staff_advance', $codes, true)
            ? StaffAdvance::query()->with('employee')->whereIn('branch_id', $branchIds)->where('status', StaffAdvanceStatus::Approved->value)->whereNull('disbursement_transaction_id')->orderBy('reference')->get()
            : collect();
        $bills = in_array('vendor_bill', $codes, true)
            ? PurchaseBill::query()->with(['vendor', 'postedPayments'])->whereIn('branch_id', $branchIds)->where('status', 'posted')->orderByDesc('invoice_date')->get()->filter(fn (PurchaseBill $bill): bool => $positive($bill->outstandingAmount()))->values()
            : collect();
        $prefill = $this->settlementPrefill($request, $purposes, $obligations, $advances, $bills);
        // A plain ?source=bank|cash|petty_cash (from a register's create button) only preselects a source this user may pay from.
        if ($prefill === [] && $sources->contains((string) $request->query('source')) && ! $request->session()->hasOldInput()) {
            $prefill = ['payment_source' => (string) $request->query('source')];
            $request->session()->now('_old_input', $prefill);
        }

        return view('accounting.payments.create', [
            'prefill' => $prefill,
            'purposes' => $purposes,
            'branches' => $access->scope($user)->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'sources' => $sources,
            'accounts' => $this->sourceAccounts($branchIds, $imprest),
            'bills' => $bills,
            'obligations' => $obligations,
            'runs' => in_array('salary_payable', $codes, true)
                ? $this->payableRuns($branchIds, $payroll)
                : collect(),
            'advances' => $advances,
            'expenseLedgers' => in_array('direct_expense', $codes, true)
                ? Account::query()->eligibleForDirectPayment()->orderBy('code')->get()->reject->hasInactiveAncestor()->values()
                : collect(),
            'payeeTypes' => PayeeType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::query()->where('employment_status', EmploymentStatus::Active->value)->whereIn('branch_id', $branchIds)->orderBy('name')->get(),
            'directoryPayees' => Payee::query()->with('payeeType')->where('is_active', true)->orderBy('name')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'funds' => Fund::query()->active()->orderBy('code')->get(),
            'settlementRequestToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreUnifiedPaymentRequest $request, UnifiedPaymentService $service): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('operational-finance', 'local');
        }
        $transaction = $service->create($data, $request->user());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Payment draft created. It follows the normal approval, disbursement and posting workflow.');
    }

    /**
     * Active cash and bank accounts of these branches (and shared bank accounts), each
     * tagged with the payment source it belongs to, as UnifiedPaymentService decides:
     * bank; cash without an imprest float; petty cash for the cash account of an active
     * float, with what the float can still pay (rechecked when the payment is saved).
     */
    private function sourceAccounts(Collection $branchIds, ImprestLedgerService $imprest): Collection
    {
        return FinancialAccount::query()->with('branch')->usableIn($branchIds)->whereIn('type', ['bank', 'cash'])->where('is_active', true)->orderBy('name')->get()
            ->map(function (FinancialAccount $account) use ($imprest): FinancialAccount {
                $float = $account->type === 'cash' ? $imprest->activeFloatFor($account) : null;
                $account->setAttribute('payment_source', $account->type === 'bank' ? UnifiedPaymentService::SOURCE_BANK : ($float ? UnifiedPaymentService::SOURCE_PETTY_CASH : UnifiedPaymentService::SOURCE_CASH));
                $account->setAttribute('float_available', $float ? AccountingDecimal::string($imprest->available($float)) : null);

                return $account;
            });
    }

    /**
     * "Settle payable" opens this screen with ?obligation=ID. The payable is only used when it is one
     * this user can see and settle (its branch, still outstanding, purpose permitted); it starts the form
     * on that branch, purpose, payable and its outstanding amount, and on its vendor when it has one.
     * A payable without a vendor is paid to a payee the user picks from the Payee Directory.
     * A staff advance (?advance=ID, optionally &source=bank|cash) is used the same way: only an approved,
     * undisbursed advance of the user's branches with no payment already in progress; the form starts on
     * its branch, the staff advance purpose, its employee, the advance and its full approved amount.
     * "Pay" on a purchase bill (?bill=ID) starts on a posted bill of the user's branches, its vendor, the
     * vendor bill purpose and what is still free to pay on it: the total less every payment that is not
     * rejected or reversed (posted or still in approval), as the vendor payment settlement reserves it.
     * Nothing is saved here, and the payment is validated again in full on submit.
     *
     * @return array<string, string>
     */
    private function settlementPrefill(Request $request, Collection $purposes, Collection $obligations, Collection $advances, Collection $bills): array
    {
        $payeeType = fn (string $kind) => PayeeType::query()->where('is_active', true)->where('source_kind', $kind)->orderBy('sort_order')->value('id');
        $bill = $request->filled('bill') ? $bills->firstWhere('id', $request->integer('bill')) : null;
        if ($bill) {
            $free = AccountingDecimal::of($bill->availableToPayAmount());
            $purpose = $purposes->first(fn ($purpose) => $purpose->behaviour === PaymentBehaviour::VendorBill);
            $vendorType = $payeeType('vendor');
            if (! $free->isPositive() || ! $purpose || ! $vendorType) {
                return [];
            }
            $prefill = ['branch_id' => (string) $bill->branch_id, 'payment_purpose_id' => (string) $purpose->id, 'purchase_bill_id' => (string) $bill->id,
                'amount' => AccountingDecimal::string($free), 'payee_type_id' => (string) $vendorType, 'payee_vendor_id' => (string) $bill->vendor_id];
            if (! $request->session()->hasOldInput()) {
                $request->session()->now('_old_input', $prefill);
            }

            return $prefill;
        }
        $obligation = $request->filled('obligation') ? $obligations->firstWhere('id', $request->integer('obligation')) : null;
        $advance = $request->filled('advance') ? $advances->firstWhere('id', $request->integer('advance')) : null;
        if ($advance && OperationalTransaction::query()->where('source_reference', 'staff_advance:'.$advance->id)
            ->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value])->exists()) {
            $advance = null;
        }
        $purpose = $purposes->first(fn ($purpose) => $purpose->behaviour === ($advance ? PaymentBehaviour::StaffAdvance : PaymentBehaviour::AccruedPayable));
        if ((! $obligation && ! $advance) || ! $purpose) {
            return [];
        }
        if ($advance) {
            $employeeType = $payeeType('employee');
            $source = (string) $request->query('source');
            $prefill = array_filter([
                'branch_id' => (string) $advance->branch_id, 'payment_purpose_id' => (string) $purpose->id, 'staff_advance_id' => (string) $advance->id,
                'amount' => number_format((float) $advance->principal_amount, 2, '.', ''),
                'payment_source' => in_array($source, [UnifiedPaymentService::SOURCE_BANK, UnifiedPaymentService::SOURCE_CASH], true) && in_array($source, $purpose->allowed_sources, true) ? $source : null,
                'payee_type_id' => $employeeType ? (string) $employeeType : null, 'payee_employee_id' => $employeeType ? (string) $advance->employee_id : null,
            ], fn ($value) => $value !== null);
        } else {
            $vendorType = $obligation->vendor_id ? $payeeType('vendor') : null;
            $prefill = array_filter([
                'branch_id' => (string) $obligation->branch_id, 'payment_purpose_id' => (string) $purpose->id, 'payable_obligation_id' => (string) $obligation->id,
                'amount' => (string) $obligation->outstandingAmount(),
                'payee_type_id' => $vendorType ? (string) $vendorType : null, 'payee_vendor_id' => $vendorType ? (string) $obligation->vendor_id : null,
            ], fn ($value) => $value !== null);
        }
        // Prefilled values stand in for old input only when the form is not being re-shown after a validation error.
        if (! $request->session()->hasOldInput()) {
            $request->session()->now('_old_input', $prefill);
        }

        return $prefill;
    }

    /** Posted payroll runs of these branches with net salary still unpaid. */
    private function payableRuns(Collection $branchIds, PayrollRunService $payroll): Collection
    {
        return PayrollRun::query()->with(['branch', 'period'])->whereIn('branch_id', $branchIds)->where('status', PayrollRunStatus::Posted->value)->get()
            ->map(function (PayrollRun $run) use ($payroll): PayrollRun {
                $run->setAttribute('unpaid_amount', $payroll->outstandingAmount($run));

                return $run;
            })
            ->filter(fn (PayrollRun $run): bool => AccountingDecimal::of($run->unpaid_amount)->isPositive())->values();
    }
}
