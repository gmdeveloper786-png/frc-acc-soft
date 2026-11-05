@extends('layouts.app')
@section('title', 'Create Receipt')
@section('page-title', 'Create Receipt')
@section('page-description', 'One-Window receipt entry for Cash and Bank transactions. Posting requires an open accounting period.')

@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('receipts.store') }}" enctype="multipart/form-data">
                @csrf

                {{-- Method Selection: Cash vs Bank --}}
                <div class="mb-4 p-3 bg-light rounded border">
                    <label class="form-label fw-bold d-block mb-2">Receiving Method</label>
                    <div class="btn-group w-100 w-md-auto" role="group" aria-label="Receiving Method">
                        <input type="radio" class="btn-check" name="method" id="method-cash" value="cash" autocomplete="off"
                            @checked($selectedMethod === 'cash')>
                        <label class="btn btn-outline-success px-4" for="method-cash">
                            <span class="fw-semibold">Cash Receipt (CR)</span>
                        </label>

                        <input type="radio" class="btn-check" name="method" id="method-bank" value="bank" autocomplete="off"
                            @checked($selectedMethod === 'bank')>
                        <label class="btn btn-outline-primary px-4" for="method-bank">
                            <span class="fw-semibold">Bank Receipt (BR)</span>
                        </label>
                    </div>
                    <div class="form-text mt-2" id="method-help-text">
                        {{ $selectedMethod === 'bank' ? 'Record funds directly deposited, transferred, or cleared in a designated bank account.' : 'Record cash received into a branch Cash in Hand account.' }}
                    </div>
                </div>

                <div class="row g-3">
                    {{-- Receiving Branch --}}
                    <div class="col-md-4">
                        <label class="form-label" for="receipt-branch">Receiving Branch</label>
                        <select class="form-select @error('branch_id') is-invalid @enderror" id="receipt-branch"
                            name="branch_id" required>
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Receipt Date --}}
                    <div class="col-md-4">
                        <label class="form-label" for="receipt-date">Receipt Date</label>
                        <input class="form-control @error('transaction_date') is-invalid @enderror" type="date"
                            id="receipt-date" name="transaction_date"
                            value="{{ old('transaction_date', today()->toDateString()) }}" required>
                        @error('transaction_date')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Amount Received (PKR) --}}
                    <div class="col-md-4">
                        <label class="form-label" for="receipt-amount">Amount Received (PKR)</label>
                        <input class="form-control text-end @error('amount') is-invalid @enderror" id="receipt-amount"
                            name="amount" value="{{ old('amount') }}" placeholder="0.00" required>
                        @error('amount')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Receiving Cash/Bank Account --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-financial-account" id="financial-account-label">
                            {{ $selectedMethod === 'bank' ? 'Receiving Bank Account' : 'Receiving Cash Account' }}
                        </label>
                        <select class="form-select @error('financial_account_id') is-invalid @enderror"
                            id="receipt-financial-account" name="financial_account_id" required>
                            <option value="">Select account</option>
                            @foreach($financialAccounts as $fa)
                                <option value="{{ $fa->id }}" data-branch-id="{{ $fa->branch_id }}"
                                    data-shared="{{ $fa->isShared() ? '1' : '0' }}" data-account-type="{{ $fa->type }}"
                                    @selected(old('financial_account_id') == $fa->id)>
                                    {{ $fa->name }} ({{ ucfirst($fa->type) }}{{ $fa->isShared() ? ' — all branches' : '' }})
                                </option>
                            @endforeach
                        </select>
                        @error('financial_account_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text" id="financial-account-help">
                            Select the account where the money was received.
                        </div>
                    </div>

                    {{-- Credit Ledger / Receipt Against --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-contra-account">Credit Ledger / Receipt Against</label>
                        <select class="form-select @error('contra_account_id') is-invalid @enderror"
                            id="receipt-contra-account" name="contra_account_id">
                            <option value="">Use system default mapped ledger (if configured)</option>
                            @foreach($ledgers as $ledger)
                                <option value="{{ $ledger->id }}" @selected(old('contra_account_id') == $ledger->id)>
                                    {{ $ledger->code }} — {{ $ledger->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('contra_account_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Select the accounting ledger to credit against this receipt, such as income
                            or an existing receivable.</div>
                    </div>

                    {{-- Received From --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-party-name">Received From (Person / Organization)</label>
                        <input class="form-control @error('party_name') is-invalid @enderror" id="receipt-party-name"
                            name="party_name" value="{{ old('party_name') }}" placeholder="Payer, donor, or customer">
                        @error('party_name')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Related Vendor (optional) --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-vendor">Related Vendor (optional)</label>
                        <select class="form-select @error('vendor_id') is-invalid @enderror" id="receipt-vendor"
                            name="vendor_id">
                            <option value="">None</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected(old('vendor_id') == $vendor->id)>{{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('vendor_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Select only when this receipt is related to a vendor (for reference only).
                        </div>
                    </div>

                    {{-- Service Allocation (optional) --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-service">Service Allocation (optional)</label>
                        <select class="form-select @error('service_id') is-invalid @enderror" id="receipt-service"
                            name="service_id">
                            <option value="">None</option>
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" @selected(old('service_id') == $service->id)>
                                    {{ $service->name }}</option>
                            @endforeach
                        </select>
                        @error('service_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Fund Allocation (optional) --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-fund">Fund Allocation (optional)</label>
                        <select class="form-select @error('fund_id') is-invalid @enderror" id="receipt-fund" name="fund_id">
                            <option value="">No fund</option>
                            @foreach($funds as $fund)
                                <option value="{{ $fund->id }}" @selected(old('fund_id') == $fund->id)>
                                    {{ $fund->label() }}{{ $fund->isRestricted() ? ' (restricted)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('fund_id')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Select the relevant fund if applicable.</div>
                    </div>

                    {{-- Receipt Reference --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-reference" id="reference-number-label">
                            {{ $selectedMethod === 'bank' ? 'Bank Transaction / Cheque / Deposit Reference' : 'Receipt Reference' }}
                        </label>
                        <input class="form-control @error('reference_number') is-invalid @enderror" id="receipt-reference"
                            name="reference_number" value="{{ old('reference_number') }}"
                            placeholder="Receipt # or Deposit slip / Ref #">
                        @error('reference_number')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Supporting Document --}}
                    <div class="col-md-6">
                        <label class="form-label" for="receipt-attachment">Supporting Document (optional)</label>
                        <input class="form-control @error('attachment') is-invalid @enderror" type="file"
                            id="receipt-attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp">
                        @error('attachment')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Attach deposit slip, receipt scan, or supporting voucher (PDF or Image, max
                            5MB).</div>
                    </div>

                    {{-- Description / Narration --}}
                    <div class="col-12">
                        <label class="form-label" for="receipt-narration">Description / Narration</label>
                        <textarea class="form-control @error('narration') is-invalid @enderror" id="receipt-narration"
                            name="narration" rows="3" required
                            placeholder="Detailed explanation of receipt...">{{ old('narration') }}</textarea>
                        @error('narration')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary" type="submit" @disabled($ledgers->isEmpty())>Save as Draft</button>
                    <a class="btn btn-outline-secondary" href="{{ route('receipts.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const branchSelect = document.getElementById('receipt-branch');
            const accountSelect = document.getElementById('receipt-financial-account');
            const accountLabel = document.getElementById('financial-account-label');
            const referenceLabel = document.getElementById('reference-number-label');
            const methodHelpText = document.getElementById('method-help-text');
            const methodCash = document.getElementById('method-cash');
            const methodBank = document.getElementById('method-bank');

            const getSelectedMethod = () => methodBank && methodBank.checked ? 'bank' : 'cash';

            const refreshAccountsAndLabels = () => {
                const method = getSelectedMethod();
                const selectedBranchId = branchSelect ? branchSelect.value : '';

                // Update Labels & Descriptions
                if (accountLabel) {
                    accountLabel.textContent = method === 'bank' ? 'Receiving Bank Account' : 'Receiving Cash Account';
                }
                if (referenceLabel) {
                    referenceLabel.textContent = method === 'bank' ? 'Bank Transaction / Cheque / Deposit Reference' : 'Receipt Reference';
                }
                if (methodHelpText) {
                    methodHelpText.textContent = method === 'bank'
                        ? 'Record money received from an external party into the selected bank account. Use Internal Transfers for transfers between FRC accounts.'
                        : 'Record cash received into a branch Cash in Hand account.';
                }

                // Filter financial accounts by branch and method
                if (!accountSelect) return;

                Array.from(accountSelect.options).forEach((option) => {
                    if (!option.value) return;

                    const matchesBranch = selectedBranchId !== '' && (option.dataset.branchId === selectedBranchId || option.dataset.shared === '1');
                    const matchesType = option.dataset.accountType === method;
                    const available = matchesBranch && matchesType;

                    option.hidden = !available;
                    option.disabled = !available;
                });

                // Reset if currently selected account is not valid for this method/branch
                if (accountSelect.selectedOptions[0]?.disabled) {
                    accountSelect.value = '';
                }
            };

            if (methodCash) methodCash.addEventListener('change', refreshAccountsAndLabels);
            if (methodBank) methodBank.addEventListener('change', refreshAccountsAndLabels);
            if (branchSelect) branchSelect.addEventListener('change', refreshAccountsAndLabels);

            refreshAccountsAndLabels();
        });
    </script>
@endsection