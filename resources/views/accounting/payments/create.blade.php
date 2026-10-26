@extends('layouts.app')
@section('title', 'Create Payment')
@section('eyebrow', 'Operational Finance')
@section('page-title', 'Create Payment')
@section('page-description', 'One entry point for outgoing bank, cash and petty cash payments. Each purpose uses its existing approval, disbursement and posting workflow.')

@php
    $money = fn ($value) => number_format((float) $value, 2);
    $sourceLabels = ['bank' => 'Bank', 'cash' => 'Cash', 'petty_cash' => 'Petty Cash'];
    $behaviourHelp = [
        'vendor_bill' => 'Select a branch and vendor to view eligible outstanding purchase bills.',
        'accrued_payable' => 'Settle an existing accrued payable, fully or in part, without recording the expense again. A payable owed to a vendor is paid to that vendor; one without a vendor to the payee you select.',
        'salary_payable' => 'Pay net salary of a posted payroll run, up to its unpaid amount. The run covers all of its employees.',
        'staff_advance' => 'Disburse an approved staff advance or loan of the selected employee, for its full approved amount. It is linked to the advance when posted.',
        'direct_expense' => 'Pay an expense directly, charged to an active expense ledger.',
    ];
@endphp

@section('content')
<form class="surface-card" method="POST" action="{{ route('payments.store') }}" enctype="multipart/form-data" id="unified-payment-form" data-submit-once data-prefill-purpose="{{ $prefill['payment_purpose_id'] ?? '' }}" data-prefill-obligation="{{ $prefill['payable_obligation_id'] ?? '' }}" data-prefill-advance="{{ $prefill['staff_advance_id'] ?? '' }}" data-prefill-bill="{{ $prefill['purchase_bill_id'] ?? '' }}">
    @csrf
    <input type="hidden" name="settlement_request_token" value="{{ old('settlement_request_token', $settlementRequestToken) }}">
    <div class="card-heading">
        <div><span class="section-kicker">Unified payment entry</span><h2>Payment details</h2></div>
    </div>
    <div class="form-stack">
        @if($errors->any())<div class="alert alert-danger">Please correct the highlighted fields.</div>@endif

        <div class="form-grid">
            <div>
                <label class="form-label" for="payment-branch"><span data-step></span>Branch</label>
                <select class="form-select @error('branch_id') is-invalid @enderror" id="payment-branch" name="branch_id" required>
                    <option value="">Select branch</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payment-source"><span data-step></span>Payment source</label>
                <select class="form-select @error('payment_source') is-invalid @enderror" id="payment-source" name="payment_source" required>
                    <option value="">Select payment source</option>
                    @foreach($sources as $value)
                        <option value="{{ $value }}" @selected(old('payment_source') === $value)>{{ $sourceLabels[$value] }}</option>
                    @endforeach
                </select>
                @error('payment_source')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payment-date">Payment date</label>
                <input class="form-control @error('transaction_date') is-invalid @enderror" type="date" id="payment-date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required>
                @error('transaction_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payment-account"><span data-step></span>Cash / bank account</label>
                <select class="form-select @error('financial_account_id') is-invalid @enderror" id="payment-account" name="financial_account_id" required>
                    <option value="">Select a branch first</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" data-source="{{ $account->payment_source }}" data-branch-id="{{ $account->branch_id }}" data-shared="{{ $account->isShared() ? '1' : '0' }}"
                            data-available="{{ $account->float_available }}" @selected((string) old('financial_account_id') === (string) $account->id)>{{ $account->name }}{{ $account->isShared() ? ' — all branches' : ' — '.$account->branch->name }}{{ $account->float_available !== null ? ' — available PKR '.$money($account->float_available) : '' }}</option>
                    @endforeach
                </select>
                @error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text" id="payment-float-info" hidden></small>
                <div class="alert alert-warning mt-2 mb-0" id="payment-no-float" hidden>No funded petty cash float is available for this branch. Set up and fund a float through Petty Cash Management.</div>
            </div>

            <div>
                <label class="form-label" for="payment-payee-type"><span data-step></span>Payee type</label>
                <select class="form-select @error('payee_type_id') is-invalid @enderror" id="payment-payee-type" name="payee_type_id">
                    <option value="">No single payee (e.g. salary run)</option>
                    @foreach($payeeTypes as $type)
                        <option value="{{ $type->id }}" data-source="{{ $type->source_kind }}" @selected((string) old('payee_type_id') === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('payee_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div data-payee-field>
                <label class="form-label" for="payment-payee-search"><span data-step></span>Payee</label>
                <input class="form-control mb-1" id="payment-payee-search" placeholder="Search payees" autocomplete="off">
                <div data-payee-source="vendor">
                    <select class="form-select @error('payee_vendor_id') is-invalid @enderror" name="payee_vendor_id" data-payee-select aria-label="Vendor">
                        <option value="">Select vendor</option>
                        @foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('payee_vendor_id') === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach
                    </select>
                    @error('payee_vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div data-payee-source="employee">
                    <select class="form-select @error('payee_employee_id') is-invalid @enderror" name="payee_employee_id" data-payee-select aria-label="Employee">
                        <option value="">Select employee</option>
                        @foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('payee_employee_id') === (string) $employee->id)>{{ $employee->name }} ({{ $employee->employee_code }})</option>@endforeach
                    </select>
                    @error('payee_employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div data-payee-source="directory">
                    <select class="form-select @error('payee_id') is-invalid @enderror" name="payee_id" data-payee-select aria-label="Payee">
                        <option value="">Select payee</option>
                        @foreach($directoryPayees as $payee)<option value="{{ $payee->id }}" data-type-id="{{ $payee->payee_type_id }}" @selected((string) old('payee_id') === (string) $payee->id)>{{ $payee->name }}{{ $payee->reference ? ' ('.$payee->reference.')' : '' }}</option>@endforeach
                    </select>
                    @error('payee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-span-2">
                <label class="form-label" for="payment-purpose"><span data-step></span>Payment purpose</label>
                <select class="form-select @error('payment_purpose_id') is-invalid @enderror" id="payment-purpose" name="payment_purpose_id" required>
                    <option value="">Select purpose</option>
                    @foreach($purposes as $purpose)
                        <option value="{{ $purpose->id }}" data-behaviour="{{ $purpose->behaviour->value }}" data-payment-sources="{{ implode(',', $purpose->allowed_sources) }}" data-sources="{{ implode(',', $purpose->behaviour->payeeSources()) }}"
                            data-requires-payee="{{ $purpose->behaviour->requiresPayee() ? '1' : '0' }}" data-fixed-ledger="{{ $purpose->debitAccount ? $purpose->debitAccount->code.' — '.$purpose->debitAccount->name : '' }}"
                            data-help="{{ $purpose->description ?: $behaviourHelp[$purpose->behaviour->value] }}" @selected((string) old('payment_purpose_id') === (string) $purpose->id)>{{ $purpose->name }}</option>
                    @endforeach
                </select>
                @error('payment_purpose_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text" id="payment-purpose-help">Choose the payee type and payee first; only matching purposes are listed.</small>
            </div>

            {{-- 6. Supporting record for the purpose; only the active purpose's fields are submitted. --}}
            <div class="form-span-2" data-behaviour-section="vendor_bill">
                <label class="form-label" for="payment-bill"><span data-step></span>Posted purchase bill</label>
                <select class="form-select @error('purchase_bill_id') is-invalid @enderror" id="payment-bill" name="purchase_bill_id" data-record data-match="vendor" data-placeholder="Select bill" data-need-payee="Select a vendor first" data-empty="No outstanding posted purchase bills for this vendor.">
                    <option value="">Select a branch first</option>
                    @foreach($bills as $bill)
                        <option value="{{ $bill->id }}" data-branch-id="{{ $bill->branch_id }}" data-vendor-id="{{ $bill->vendor_id }}" data-hint="Outstanding PKR {{ $money($bill->outstandingAmount()) }} (partial payment allowed)" @selected((string) old('purchase_bill_id') === (string) $bill->id)>{{ $bill->bill_number }} — {{ $bill->vendor->name }} — Outstanding PKR {{ $money($bill->outstandingAmount()) }}</option>
                    @endforeach
                </select>
                @error('purchase_bill_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2" data-behaviour-section="accrued_payable">
                <label class="form-label" for="payment-obligation"><span data-step></span>Accrued payable</label>
                <select class="form-select @error('payable_obligation_id') is-invalid @enderror" id="payment-obligation" name="payable_obligation_id" data-record data-match="obligation" data-need-payee="Select a payee first" data-placeholder="Select payable" data-empty="No open accrued payables for this branch and payee.">
                    <option value="">Select a branch first</option>
                    @foreach($obligations as $obligation)
                        <option value="{{ $obligation->id }}" data-branch-id="{{ $obligation->branch_id }}" data-vendor-id="{{ $obligation->vendor_id }}" data-hint="Outstanding PKR {{ $money($obligation->outstandingAmount()) }} (partial settlement allowed)" @selected((string) old('payable_obligation_id') === (string) $obligation->id)>#{{ $obligation->id }} — {{ $obligation->vendor?->name ?? 'No vendor' }} — {{ $obligation->obligation_date?->format('d M Y') }} — Outstanding PKR {{ $money($obligation->outstandingAmount()) }}</option>
                    @endforeach
                </select>
                @error('payable_obligation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2" data-behaviour-section="salary_payable">
                <label class="form-label" for="payment-run"><span data-step></span>Posted payroll run</label>
                <select class="form-select @error('payroll_run_id') is-invalid @enderror" id="payment-run" name="payroll_run_id" data-record data-placeholder="Select payroll run" data-empty="No posted payroll runs with unpaid salary for this branch.">
                    <option value="">Select a branch first</option>
                    @foreach($runs as $run)
                        <option value="{{ $run->id }}" data-branch-id="{{ $run->branch_id }}" data-hint="Unpaid net salary PKR {{ $money($run->unpaid_amount) }}" @selected((string) old('payroll_run_id') === (string) $run->id)>#{{ $run->id }} — {{ $run->period->name }} — {{ $run->branch->name }} — Unpaid PKR {{ $money($run->unpaid_amount) }}</option>
                    @endforeach
                </select>
                @error('payroll_run_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2" data-behaviour-section="staff_advance">
                <label class="form-label" for="payment-advance"><span data-step></span>Approved staff advance / loan</label>
                <select class="form-select @error('staff_advance_id') is-invalid @enderror" id="payment-advance" name="staff_advance_id" data-record data-match="employee" data-need-payee="Select an employee first" data-placeholder="Select advance" data-empty="No approved, undisbursed advances for this branch and employee.">
                    <option value="">Select a branch first</option>
                    @foreach($advances as $advance)
                        <option value="{{ $advance->id }}" data-branch-id="{{ $advance->branch_id }}" data-employee-id="{{ $advance->employee_id }}" data-amount="{{ number_format((float) $advance->principal_amount, 2, '.', '') }}" data-hint="Disbursed for the full approved amount PKR {{ $money($advance->principal_amount) }}; linked to the advance automatically when posted." @selected((string) old('staff_advance_id') === (string) $advance->id)>{{ $advance->reference }} — {{ $advance->employee?->name }} — PKR {{ $money($advance->principal_amount) }}</option>
                    @endforeach
                </select>
                @error('staff_advance_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2" data-behaviour-section="direct_expense">
                <label class="form-label" for="payment-expense"><span data-step></span>Expense ledger</label>
                <div class="form-control-plaintext fw-semibold" id="payment-fixed-ledger" hidden></div>
                @if($expenseLedgers->isEmpty())
                    <div class="alert alert-warning mb-2">No expense ledgers are approved for direct payment yet. An authorized accountant can approve one in Chart of Accounts (edit the ledger and tick "Direct payment eligible").</div>
                @endif
                <select class="form-select @error('debit_account_id') is-invalid @enderror" id="payment-expense" name="debit_account_id">
                    <option value="">Select expense ledger</option>
                    @foreach($expenseLedgers as $ledger)
                        <option value="{{ $ledger->id }}" @selected((string) old('debit_account_id') === (string) $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>
                    @endforeach
                </select>
                @error('debit_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div data-behaviour-section="direct_expense">
                <label class="form-label" for="payment-service">Service allocation (optional)</label>
                <select class="form-select" id="payment-service" name="service_id">
                    <option value="">None</option>
                    @foreach($services as $service)<option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }}</option>@endforeach
                </select>
            </div>
            <div data-behaviour-section="direct_expense">
                <label class="form-label" for="payment-fund">Fund (optional)</label>
                <select class="form-select @error('fund_id') is-invalid @enderror" id="payment-fund" name="fund_id">
                    <option value="">No fund</option>
                    @foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) old('fund_id') === (string) $fund->id)>{{ $fund->label() }}{{ $fund->isRestricted() ? ' (restricted)' : '' }}</option>@endforeach
                </select>
                @error('fund_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2"><small class="form-text" id="payment-record-hint"></small></div>

            <div>
                <label class="form-label" for="payment-amount"><span data-step></span>Amount (PKR)</label>
                <input class="form-control text-end @error('amount') is-invalid @enderror" id="payment-amount" name="amount" value="{{ old('amount') }}" required>
                @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payment-reference" id="payment-reference-label">Reference / cheque no.</label>
                <input class="form-control @error('reference_number') is-invalid @enderror" id="payment-reference" name="reference_number" value="{{ old('reference_number') }}" maxlength="100">
                @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="payment-narration">Narration <small class="text-muted">(optional; a description is generated from the record)</small></label>
                <textarea class="form-control @error('narration') is-invalid @enderror" id="payment-narration" name="narration" rows="2" maxlength="2000">{{ old('narration') }}</textarea>
                @error('narration')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="payment-attachment">Attachment (optional)</label>
                <input class="form-control @error('attachment') is-invalid @enderror" type="file" id="payment-attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp">
                @error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-primary" href="{{ route('dashboard') }}">Cancel</a>
            <button class="btn btn-primary" id="payment-save">Save payment draft</button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const $ = (id) => document.getElementById(id);
    const branch = $('payment-branch'), paymentSource = $('payment-source'), account = $('payment-account'), type = $('payment-payee-type'), purpose = $('payment-purpose');
    const floatInfo = $('payment-float-info'), noFloat = $('payment-no-float');
    const search = $('payment-payee-search'), amount = $('payment-amount'), help = $('payment-purpose-help'), hint = $('payment-record-hint');
    const fixedLedger = $('payment-fixed-ledger'), ledger = $('payment-expense');
    const payeeField = document.querySelector('[data-payee-field]');
    const toggle = (element, visible) => {
        element.hidden = !visible;
        element.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = !visible; });
    };
    const setOption = (option, available) => { option.hidden = !available; option.disabled = !available; };
    const clearIfUnavailable = (select) => { if (select.selectedOptions[0]?.disabled) { select.value = ''; } };

    const fitsPaymentSource = (option) => paymentSource.value === '' || option.dataset.paymentSources.split(',').includes(paymentSource.value);

    // A payable opened from "Settle payable" keeps its purpose and payable pending until they become
    // available, then selects each of them once. A record that belongs to a payee waits for the payee,
    // because choosing the payee clears records; a manual choice ends the prefill for that field.
    const form = $('unified-payment-form');
    const prefill = [[purpose, form.dataset.prefillPurpose], [$('payment-obligation'), form.dataset.prefillObligation], [$('payment-advance'), form.dataset.prefillAdvance], [$('payment-bill'), form.dataset.prefillBill]].filter(([, value]) => value).map(([select, value]) => ({ select, value }));
    prefill.forEach((item) => item.select.addEventListener('change', () => { item.done = true; }));
    const applyPrefill = () => prefill.filter((item) => !item.done && item.select.value === '').some((item) => {
        const payeeChosen = (document.querySelector('[data-payee-source]:not([hidden]) select')?.value ?? '') !== '';
        if ((item.select.dataset.needPayee && !payeeChosen) || !Array.from(item.select.options).some((option) => option.value === item.value && !option.disabled)) {
            return false;
        }
        item.select.value = item.value;
        item.done = true;

        return true;
    });
    const referenceLabels = { bank: 'Reference / cheque no.', cash: 'Payment reference', petty_cash: 'Voucher / payment reference' };

    const refresh = () => {
        $('payment-reference-label').textContent = referenceLabels[paymentSource.value] ?? referenceLabels.bank;
        // "No single payee" (salary run) only where the chosen source still offers a purpose without a payee;
        // otherwise (petty cash) a payee type must be chosen.
        const noPayeeAllowed = Array.from(purpose.options).some((option) => option.value && option.dataset.requiresPayee === '0' && fitsPaymentSource(option));
        type.options[0].textContent = noPayeeAllowed ? 'No single payee (e.g. salary run)' : 'Select payee type';
        type.required = !noPayeeAllowed;
        const source = type.selectedOptions[0]?.dataset.source ?? '';
        // Purposes that accept this kind of payee; with no payee type, only purposes that need no single payee.
        Array.from(purpose.options).forEach((option) => {
            if (option.value) {
                // ...and that this user may pay from the chosen payment source (petty cash: direct expense only).
                setOption(option, fitsPaymentSource(option) && (source ? option.dataset.sources.split(',').includes(source) : option.dataset.requiresPayee === '0'));
            }
        });
        clearIfUnavailable(purpose);
        const chosen = purpose.value ? purpose.selectedOptions[0] : null;
        const behaviour = chosen?.dataset.behaviour ?? '';
        help.textContent = chosen?.dataset.help ?? 'Choose the payee type and payee first; only matching purposes are listed.';

        // The payee picker shows the source of the payee type; directory payees are limited to that type.
        const needsPayee = source !== '' && chosen?.dataset.requiresPayee !== '0';
        toggle(payeeField, needsPayee);
        document.querySelectorAll('[data-payee-source]').forEach((box) => toggle(box, needsPayee && box.dataset.payeeSource === source));
        const term = search.value.trim().toLowerCase();
        document.querySelectorAll('[data-payee-select]').forEach((select) => {
            Array.from(select.options).forEach((option) => {
                if (option.value) {
                    const ofType = source !== 'directory' || option.dataset.typeId === type.value;
                    setOption(option, ofType && (term === '' || option.textContent.toLowerCase().includes(term) || option.selected));
                }
            });
            clearIfUnavailable(select);
        });
        const payeeSelect = document.querySelector('[data-payee-source]:not([hidden]) select');
        const payeeId = payeeSelect?.value ?? '';

        document.querySelectorAll('[data-behaviour-section]').forEach((section) => toggle(section, section.dataset.behaviourSection === behaviour));
        // Records of the branch that belong to the chosen payee.
        document.querySelectorAll('select[data-record]').forEach((select) => {
            Array.from(select.options).forEach((option) => {
                if (!option.value) {
                    return;
                }
                let match = branch.value !== '' && option.dataset.branchId === branch.value;
                if (select.dataset.match === 'vendor') { match &&= option.dataset.vendorId === payeeId; }
                if (select.dataset.match === 'employee') { match &&= option.dataset.employeeId === payeeId; }
                if (select.dataset.match === 'obligation') { match &&= source === 'directory' ? option.dataset.vendorId === '' : option.dataset.vendorId === payeeId; }
                setOption(option, match);
            });
            clearIfUnavailable(select);
            const hasChoices = Array.from(select.options).some((option) => option.value && !option.disabled);
            // Ask for what is missing before saying the list is empty: branch first, then the payee the records belong to.
            select.options[0].textContent = branch.value === '' ? 'Select a branch first'
                : (select.dataset.needPayee && payeeId === '' ? select.dataset.needPayee : (hasChoices ? select.dataset.placeholder : select.dataset.empty));
            select.required = !select.disabled;
        });
        // Accounts of the chosen source in the branch (shared bank accounts in every branch).
        // A petty cash float is offered only while it still has money available.
        Array.from(account.options).forEach((option) => {
            if (option.value) {
                const funded = option.dataset.source !== 'petty_cash' || parseFloat(option.dataset.available || '0') > 0;
                setOption(option, branch.value !== '' && option.dataset.source === paymentSource.value && funded
                    && (option.dataset.branchId === branch.value || option.dataset.shared === '1'));
            }
        });
        clearIfUnavailable(account);
        const accountChoices = Array.from(account.options).some((option) => option.value && !option.disabled);
        const emptyAccounts = { bank: 'No bank account is available for this branch.', cash: 'No cash account is available for this branch.', petty_cash: 'No funded petty cash float' };
        account.options[0].textContent = branch.value === '' ? 'Select a branch first'
            : (paymentSource.value === '' ? 'Select a payment source first' : (accountChoices ? 'Select account' : emptyAccounts[paymentSource.value]));
        noFloat.hidden = !(paymentSource.value === 'petty_cash' && branch.value !== '' && !accountChoices);
        // No account for this branch and source: nothing valid can be saved (the server checks again anyway).
        $('payment-save').disabled = branch.value !== '' && paymentSource.value !== '' && !accountChoices;
        const chosenAccount = account.value ? account.selectedOptions[0] : null;
        floatInfo.hidden = !(chosenAccount && paymentSource.value === 'petty_cash');
        floatInfo.textContent = floatInfo.hidden ? '' : `Available in this float now: PKR ${Number(chosenAccount.dataset.available).toLocaleString('en-PK', { minimumFractionDigits: 2 })}. It is checked again when the payment is saved and at every approval step.`;

        const record = document.querySelector('[data-behaviour-section]:not([hidden]) select[data-record]');
        const selected = record?.value ? record.selectedOptions[0] : null;
        hint.textContent = selected?.dataset.hint ?? '';
        amount.readOnly = Boolean(selected?.dataset.amount);
        if (selected?.dataset.amount) {
            amount.value = selected.dataset.amount;
        }
        // A purpose may fix its approved expense ledger.
        const fixed = behaviour === 'direct_expense' ? (chosen?.dataset.fixedLedger ?? '') : '';
        fixedLedger.hidden = fixed === '';
        fixedLedger.textContent = fixed;
        ledger.hidden = fixed !== '';
        ledger.disabled = behaviour !== 'direct_expense' || fixed !== '';
        ledger.required = !ledger.disabled;
        // Number only the steps that are shown, so the sequence never skips.
        let step = 0;
        document.querySelectorAll('[data-step]').forEach((label) => { label.textContent = label.closest('[hidden]') ? '' : `${++step}. `; });
        if (applyPrefill()) {
            refresh();
        }
    };

    // Upstream changes clear what depends on them: the payee when the type changes, and the chosen record
    // whenever the branch, payee type or payee changes.
    const clearRecords = () => document.querySelectorAll('select[data-record]').forEach((select) => { select.value = ''; });
    type.addEventListener('change', () => { document.querySelectorAll('[data-payee-select]').forEach((select) => { select.value = ''; }); search.value = ''; clearRecords(); refresh(); });
    branch.addEventListener('change', () => { clearRecords(); account.value = ''; });
    paymentSource.addEventListener('change', () => { account.value = ''; refresh(); });
    account.addEventListener('change', refresh);
    document.querySelectorAll('[data-payee-select]').forEach((select) => select.addEventListener('change', () => { clearRecords(); refresh(); }));
    search.addEventListener('input', refresh);
    branch.addEventListener('change', refresh);
    purpose.addEventListener('change', () => { clearRecords(); refresh(); });
    document.querySelectorAll('select[data-record]').forEach((select) => select.addEventListener('change', refresh));
    refresh();
});
</script>
@endsection
