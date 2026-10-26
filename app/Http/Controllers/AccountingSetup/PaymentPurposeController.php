<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Enums\PaymentBehaviour;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentPurpose;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configurable payment purposes. Each one picks a protected accounting
 * behaviour; only a direct expense purpose may fix an (expense) debit ledger.
 * Once payments use a purpose, its code, behaviour and ledger are frozen.
 */
class PaymentPurposeController extends Controller
{
    private const AUDITED = ['name', 'code', 'description', 'behaviour', 'debit_account_id', 'is_active', 'sort_order'];

    public function index(Request $request): View
    {
        $purposes = PaymentPurpose::query()->with('debitAccount')
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status') === 'active'))
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('accounting-setup.payment-purposes.index', compact('purposes'));
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);

        return view('accounting-setup.payment-purposes.form', ['purpose' => new PaymentPurpose(['is_active' => true]), 'expenseLedgers' => $this->expenseLedgers()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $purpose = PaymentPurpose::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
        $audit->log('payment_purposes.created', $purpose, $purpose->only(self::AUDITED));

        return redirect()->route('payment-purposes.index')->with('status', 'Payment purpose created.');
    }

    public function edit(Request $request, PaymentPurpose $paymentPurpose): View
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);

        return view('accounting-setup.payment-purposes.form', ['purpose' => $paymentPurpose, 'expenseLedgers' => $this->expenseLedgers(), 'locked' => $paymentPurpose->isReferenced()]);
    }

    public function update(Request $request, PaymentPurpose $paymentPurpose, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $data = $this->validated($request, $paymentPurpose);
        if ($paymentPurpose->isReferenced() && ($data['code'] !== $paymentPurpose->code || $data['behaviour'] !== $paymentPurpose->behaviour->value || (int) $data['debit_account_id'] !== (int) $paymentPurpose->debit_account_id)) {
            throw ValidationException::withMessages(['behaviour' => 'Payments already use this purpose, so its code, accounting behaviour and debit ledger cannot change. Create a new purpose instead.']);
        }
        $before = $paymentPurpose->only(self::AUDITED);
        $paymentPurpose->update($data);
        $audit->log('payment_purposes.updated', $paymentPurpose, ['before' => $before, 'after' => $paymentPurpose->fresh()->only(self::AUDITED)]);

        return redirect()->route('payment-purposes.index')->with('status', 'Payment purpose updated.');
    }

    public function toggle(Request $request, PaymentPurpose $paymentPurpose, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $paymentPurpose->update(['is_active' => ! $paymentPurpose->is_active]);
        $audit->log('payment_purposes.status_changed', $paymentPurpose, ['is_active' => $paymentPurpose->is_active]);

        return back()->with('status', 'Payment purpose status updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PaymentPurpose $purpose = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('payment_purposes', 'code')->ignore($purpose?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'behaviour' => ['required', Rule::enum(PaymentBehaviour::class)],
            'debit_account_id' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use lower-case letters, digits and underscores only.']);
        $behaviour = PaymentBehaviour::from($data['behaviour']);
        if (filled($data['debit_account_id'] ?? null)) {
            // A fixed ledger is only for a direct expense, and only an active expense ledger.
            if (! $behaviour->usesDebitLedger() || ! $this->expenseLedgers()->contains('id', (int) $data['debit_account_id'])) {
                throw ValidationException::withMessages(['debit_account_id' => $behaviour->usesDebitLedger()
                    ? 'Select an expense ledger approved for direct payment.'
                    : 'This accounting behaviour takes its debit account from its own record; it cannot have a fixed ledger.']);
            }
        }

        return [...$data, 'debit_account_id' => filled($data['debit_account_id'] ?? null) ? (int) $data['debit_account_id'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active')];
    }

    private function expenseLedgers()
    {
        return Account::query()->eligibleForDirectPayment()->orderBy('code')->get()->reject->hasInactiveAncestor()->values();
    }
}
