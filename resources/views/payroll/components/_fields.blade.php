@php
    $record = $component ?? null;
    $selectedAccount = old('account_id', $record?->account_id);
@endphp
<div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="code">Code</label><input class="form-control @error('code') is-invalid @enderror" id="code" name="code" maxlength="40" value="{{ old('code',$record?->code) }}" @if($record) disabled @else required @endif>@error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-5"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="255" value="{{ old('name',$record?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-2"><label class="form-label" for="type">Type</label><select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>@foreach(\App\Enums\PayrollComponentType::cases() as $type)<option value="{{ $type->value }}" @selected(old('type',$record?->type?->value)===$type->value)>{{ $type->label() }}</option>@endforeach</select>@error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-2"><label class="form-label" for="sort_order">Sort order</label><input class="form-control @error('sort_order') is-invalid @enderror" id="sort_order" type="number" min="0" max="65535" name="sort_order" value="{{ old('sort_order',$record?->sort_order ?? 100) }}">@error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-4"><label class="form-label" for="category">Category</label><select class="form-select @error('category') is-invalid @enderror" id="category" name="category" required>@foreach(\App\Enums\PayrollComponentCategory::cases() as $category)<option value="{{ $category->value }}" @selected(old('category',$record?->category?->value)===$category->value)>{{ $category->label() }}</option>@endforeach</select>@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-6"><label class="form-label" for="account_id">Ledger</label>
        <select class="form-select @error('account_id') is-invalid @enderror" id="account_id" name="account_id">
            <option value="">No ledger (earning: use the Salary Expense mapping)</option>
            <optgroup label="Earning — expense ledger overriding Salary Expense">@foreach($earningAccounts as $account)<option value="{{ $account->id }}" @selected($selectedAccount==$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</optgroup>
            <optgroup label="Deduction — liability or asset ledger credited on accrual">@foreach($deductionAccounts as $account)<option value="{{ $account->id }}" @selected($selectedAccount==$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</optgroup>
        </select>@error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active',$record?->is_active ?? true))><label class="form-check-label" for="is_active">Active</label></div></div>
</div>
<p class="card-copy">A component is only a label, a direction and a ledger. It has no rate, bracket or formula: every amount is entered on the salary structure, on the payroll line or through a reviewed import. A deduction with amounts on a run must have a ledger before that run can be posted.</p>
