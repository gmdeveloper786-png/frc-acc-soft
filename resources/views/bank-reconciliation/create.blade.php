@extends('layouts.app') @section('title','New Bank Reconciliation') @section('eyebrow','Bank reconciliation') @section('page-title','New Bank Reconciliation')
@section('page-description','Choose the bank account and the statement to reconcile. The statement date must be after the latest approved reconciliation of the account.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.index') }}">Back</a>@endsection
@section('content')
<form method="POST" action="{{ route('bank-reconciliations.store') }}" data-submit-once>@csrf
<section class="surface-card">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="financial_account_id">Bank account</label>
            <select class="form-select @error('financial_account_id') is-invalid @enderror" id="financial_account_id" name="financial_account_id" required>
                <option value="">Select a bank account</option>
                @foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int) old('financial_account_id', request('financial_account_id')) === $account->id)>{{ $account->name }} — {{ $account->branch->name }}</option>@endforeach
            </select>
            @error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="bank_statement_id">Imported statement (fills the date and closing balance)</label>
            <select class="form-select @error('bank_statement_id') is-invalid @enderror" id="bank_statement_id" name="bank_statement_id">
                <option value="">Enter the statement date and balance manually</option>
                @foreach($statements as $statement)
                    <option value="{{ $statement->id }}"
                        data-account-id="{{ $statement->financial_account_id }}"
                        data-period-to="{{ $statement->period_to->format('Y-m-d') }}"
                        data-closing-balance="{{ number_format((float) $statement->closing_balance, 2, '.', '') }}"
                        @selected((int) old('bank_statement_id') === $statement->id)>
                        {{ $accounts->firstWhere('id', $statement->financial_account_id)?->name }} — {{ $statement->period_from->format('d M Y') }} to {{ $statement->period_to->format('d M Y') }} (closing {{ \App\Services\BankReconciliation\Amount::format($statement->closing_balance) }})
                    </option>
                @endforeach
            </select>
            @error('bank_statement_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="statement_date">Statement date</label>
            <input class="form-control @error('statement_date') is-invalid @enderror" type="date" id="statement_date" name="statement_date" value="{{ old('statement_date') }}">
            @error('statement_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="statement_closing_balance">Closing balance as per statement</label>
            <input class="form-control font-monospace @error('statement_closing_balance') is-invalid @enderror" id="statement_closing_balance" name="statement_closing_balance" inputmode="decimal" value="{{ old('statement_closing_balance') }}" placeholder="0.00">
            @error('statement_closing_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <p class="card-copy mt-3">When an imported statement is selected, its period end date and closing balance are used. An account has one reconciliation in progress at a time.</p>
    <div class="form-actions"><button class="btn btn-primary">Start reconciliation</button></div>
</section>
</form>

<script id="bank-reconciliation-create-script">
document.addEventListener('DOMContentLoaded', () => {
    const bankAccountSelect = document.getElementById('financial_account_id');
    const statementSelect = document.getElementById('bank_statement_id');
    const statementDateInput = document.getElementById('statement_date');
    const closingBalanceInput = document.getElementById('statement_closing_balance');

    if (!bankAccountSelect || !statementSelect || !statementDateInput || !closingBalanceInput) {
        return;
    }

    const syncStatementFields = () => {
        const selectedOption = statementSelect.selectedOptions[0];
        if (statementSelect.value && selectedOption && selectedOption.dataset.periodTo) {
            statementDateInput.value = selectedOption.dataset.periodTo;
            closingBalanceInput.value = selectedOption.dataset.closingBalance;
            statementDateInput.readOnly = true;
            closingBalanceInput.readOnly = true;
        } else {
            statementDateInput.readOnly = false;
            closingBalanceInput.readOnly = false;
        }
    };

    const filterStatements = (isAccountChange = false) => {
        const selectedAccountId = bankAccountSelect.value;
        let selectedStillValid = false;

        Array.from(statementSelect.options).forEach((option) => {
            if (!option.value) {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const matches = selectedAccountId !== '' && option.dataset.accountId === selectedAccountId;
            option.hidden = !matches;
            option.disabled = !matches;

            if (matches && option.value === statementSelect.value) {
                selectedStillValid = true;
            }
        });

        if (statementSelect.value && !selectedStillValid) {
            statementSelect.value = '';
            statementDateInput.value = '';
            closingBalanceInput.value = '';
            statementDateInput.readOnly = false;
            closingBalanceInput.readOnly = false;
        } else if (isAccountChange && !statementSelect.value) {
            statementDateInput.value = '';
            closingBalanceInput.value = '';
            statementDateInput.readOnly = false;
            closingBalanceInput.readOnly = false;
        } else {
            syncStatementFields();
        }
    };

    bankAccountSelect.addEventListener('change', () => {
        filterStatements(true);
    });

    statementSelect.addEventListener('change', () => {
        if (statementSelect.value) {
            syncStatementFields();
        } else {
            statementDateInput.value = '';
            closingBalanceInput.value = '';
            statementDateInput.readOnly = false;
            closingBalanceInput.readOnly = false;
        }
    });

    filterStatements(false);
});
</script>
@endsection
