@extends('layouts.app')
@php
    $account = $statement->financialAccount;
    $amount = fn ($value) => \App\Services\BankReconciliation\Amount::format($value);
    $headers = $statement->detected_headers ?? [];
    $columnOptions = function (string $field) use ($headers): string {
        $selected = old($field);
        $html = '<option value="">— not mapped —</option>';
        foreach ($headers as $letter => $label) {
            $html .= '<option value="'.e($letter).'"'.($selected === $letter ? ' selected' : '').'>'.e($letter.' — '.$label).'</option>';
        }

        return $html;
    };
@endphp
@section('title','Bank Statement #'.$statement->id) @section('eyebrow','Bank statement') @section('page-title',$statement->isImported() ? 'Bank Statement' : 'Map Statement Columns')
@section('page-description',$account->name.' • '.$statement->branch->name.' • '.$statement->original_filename.' • '.$statement->statusLabel())
@section('page-actions')
<div class="d-flex gap-2">
    @can('bank_reconciliation.prepare')
        <form method="POST" action="{{ route('bank-statements.destroy', $statement) }}" data-submit-once>@csrf @method('DELETE')<button class="btn btn-outline-danger" data-confirm="Remove this statement and delete its stored file?">{{ $statement->isImported() ? 'Withdraw statement' : 'Cancel upload' }}</button></form>
    @endcan
    <a class="btn btn-outline-primary" href="{{ route('bank-statements.index') }}">Back</a>
</div>
@endsection
@section('content')
@foreach(['mapping','statement','period_from'] as $field) @error($field)<div class="alert alert-danger">@foreach($errors->get($field) as $message)<div>{{ $message }}</div>@endforeach</div>@enderror @endforeach

@if(! $statement->isImported())
    @can('bank_reconciliation.prepare')
    <form method="POST" action="{{ route('bank-statements.import', $statement) }}" data-submit-once>@csrf
    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">Step 2 — headings found on row {{ $statement->header_row }}; cell values are not displayed</span><h2>Column mapping</h2></div><span class="status-badge {{ $statement->badge() }}">{{ $statement->statusLabel() }}</span></div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="date_column">Date (required)</label><select class="form-select" id="date_column" name="date_column" required>{!! $columnOptions('date_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="date_format">Date format</label><select class="form-select" id="date_format" name="date_format" required>@foreach($dateFormats as $format => $label)<option value="{{ $format }}" @selected(old('date_format') === $format)>{{ $label }}</option>@endforeach</select><span class="table-subtext">Excel date cells are read directly.</span></div>
            <div class="col-md-4"><label class="form-label" for="description_column">Description</label><select class="form-select" id="description_column" name="description_column">{!! $columnOptions('description_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="reference_column">Reference / cheque number</label><select class="form-select" id="reference_column" name="reference_column">{!! $columnOptions('reference_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="balance_column">Running balance (checked against the amounts)</label><select class="form-select" id="balance_column" name="balance_column">{!! $columnOptions('balance_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="amount_mode">Amount layout</label><select class="form-select" id="amount_mode" name="amount_mode" required><option value="split" @selected(old('amount_mode', 'split') === 'split')>Separate withdrawal and deposit columns</option><option value="signed" @selected(old('amount_mode') === 'signed')>One signed amount column (deposits +, withdrawals −)</option></select></div>
            <div class="col-md-4"><label class="form-label" for="withdrawal_column">Withdrawal / debit column</label><select class="form-select" id="withdrawal_column" name="withdrawal_column">{!! $columnOptions('withdrawal_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="deposit_column">Deposit / credit column</label><select class="form-select" id="deposit_column" name="deposit_column">{!! $columnOptions('deposit_column') !!}</select></div>
            <div class="col-md-4"><label class="form-label" for="amount_column">Signed amount column</label><select class="form-select" id="amount_column" name="amount_column">{!! $columnOptions('amount_column') !!}</select></div>
        </div>
        <h3 class="h6 mt-4">As stated on the statement</h3>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label" for="period_from">Period from</label><input class="form-control" type="date" id="period_from" name="period_from" value="{{ old('period_from') }}" required></div>
            <div class="col-md-3"><label class="form-label" for="period_to">Period to</label><input class="form-control" type="date" id="period_to" name="period_to" value="{{ old('period_to') }}" required></div>
            <div class="col-md-3"><label class="form-label" for="opening_balance">Opening balance</label><input class="form-control font-monospace" id="opening_balance" name="opening_balance" inputmode="decimal" value="{{ old('opening_balance') }}" placeholder="0.00" required></div>
            <div class="col-md-3"><label class="form-label" for="closing_balance">Closing balance</label><input class="form-control font-monospace" id="closing_balance" name="closing_balance" inputmode="decimal" value="{{ old('closing_balance') }}" placeholder="0.00" required></div>
        </div>
        <p class="card-copy mt-3">Rows without an amount (balance brought forward, sub-totals) are skipped. Every other row must have a valid date inside the period and an amount with at most two decimals, and opening balance + lines must equal the closing balance. If any row fails, nothing is imported.</p>
        <div class="form-actions"><button class="btn btn-primary">Validate and import lines</button></div>
    </section>
    </form>
    @else
        <x-empty-state title="Awaiting column mapping" description="A user who prepares reconciliations must map the columns of this statement." />
    @endcan
@else
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">{{ $statement->period_from->format('d M Y') }} – {{ $statement->period_to->format('d M Y') }} • opening {{ $amount($statement->opening_balance) }} • closing {{ $amount($statement->closing_balance) }}</span><h2>Statement lines</h2></div><span class="status-badge {{ $statement->badge() }}">{{ $statement->line_count }} lines</span></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Status</th></tr></thead><tbody>
        @foreach($lines as $line)
            <tr>
                <td>{{ $line->transaction_date->format('d M Y') }}</td>
                <td>{{ $line->description }}</td>
                <td>{{ $line->reference }}</td>
                <td class="text-end font-monospace">{{ $amount($line->amount) }}</td>
                <td class="text-end font-monospace">{{ $line->running_balance !== null ? $amount($line->running_balance) : '' }}</td>
                <td><span class="status-badge {{ ['matched' => 'success', 'ignored' => 'neutral'][$line->status] ?? 'pending' }}">{{ ucfirst($line->status) }}</span></td>
            </tr>
        @endforeach
        </tbody></table></div>
        {{ $lines->links() }}
    </section>
@endif
@endsection
