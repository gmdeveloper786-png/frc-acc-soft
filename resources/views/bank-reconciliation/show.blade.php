@extends('layouts.app')
@php
    $account = $reconciliation->financialAccount;
    $amount = fn ($value) => \App\Services\BankReconciliation\Amount::format($value);
    $bookAmount = fn ($line) => \App\Services\BankReconciliation\Amount::journalLine($line->debit, $line->credit);
    $currency = $appSettings['currency_code'];
    $user = auth()->user();
    $reconciled = $figures['difference']->isZero();
    $candidatesByAmount = $bookCandidates->groupBy(fn ($line) => \App\Services\AccountingDecimal::string($bookAmount($line)));
@endphp
@section('title','Bank Reconciliation — '.$account->name) @section('eyebrow','Bank reconciliation') @section('page-title',$account->name.' — '.$reconciliation->statement_date->format('d M Y'))
@section('page-description',($account->isShared() ? 'All Branches — Shared (custodian: '.$account->branch->name.')' : $account->branch->name).' • '.($account->bank_name ?? 'Bank').($account->maskedNumber() ? ' • '.$account->maskedNumber() : '').' • '.$reconciliation->statusLabel())
@section('page-actions')
<div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.statement', $reconciliation) }}">Reconciliation statement</a>
    @can('finance_reports.export')@include('accounting.reports._export-links', ['exportRoute' => 'bank-reconciliations.export', 'exportParameters' => ['bankReconciliation' => $reconciliation->id]])@endcan
    <a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.index') }}">Back</a>
</div>
@endsection
@section('content')
@foreach(['status','approval','reason','statement_line_id','journal_line_id','note'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Balance as per statement" value="{{ $currency }} {{ $amount($figures['statement_balance']) }}" icon="S" tone="navy" status="{{ $reconciliation->statement_date->format('d M Y') }}" description="Closing balance stated by the bank"/>
    <x-kpi-card label="Balance as per bank book" value="{{ $currency }} {{ $amount($figures['book_balance']) }}" icon="B" tone="teal" status="{{ $figures['frozen'] ? 'Frozen at approval' : 'Posted entries' }}" description="Posted and reversed journal lines of this account's ledger in its branch, up to the statement date"/>
    <x-kpi-card label="Uncleared deposits" value="{{ $currency }} {{ $amount($figures['uncleared_total']) }}" icon="+" tone="amber" status="{{ $figures['uncleared']->count() }} items" description="Book debits not yet on a statement"/>
    <x-kpi-card label="Unpresented payments" value="{{ $currency }} {{ $amount($figures['unpresented_total']) }}" icon="−" tone="rose" status="{{ $figures['unpresented']->count() }} items" description="Book credits not yet on a statement"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $formula }}</span><h2>Reconciliation</h2></div><span class="status-badge {{ $reconciliation->badge() }}">{{ $reconciliation->statusLabel() }}</span></div>
    <div class="table-responsive"><table class="table accounting-table"><tbody>
        <tr><td>Balance as per bank statement</td><td class="text-end font-monospace">{{ $amount($figures['statement_balance']) }}</td></tr>
        <tr><td>Add: uncleared deposits</td><td class="text-end font-monospace">{{ $amount($figures['uncleared_total']) }}</td></tr>
        <tr><td>Less: unpresented payments</td><td class="text-end font-monospace">({{ $amount($figures['unpresented_total']) }})</td></tr>
        <tr><th>Adjusted bank balance</th><th class="text-end font-monospace">{{ $amount($figures['adjusted_balance']) }}</th></tr>
        <tr><th>Balance as per bank book</th><th class="text-end font-monospace">{{ $amount($figures['book_balance']) }}</th></tr>
        <tr><th>Difference</th><th class="text-end font-monospace"><span class="status-badge {{ $reconciled ? 'success' : 'danger' }}">{{ $amount($figures['difference']) }}</span></th></tr>
    </tbody></table></div>
    @if($reconciliation->isCancelled())
        <div class="alert alert-secondary mb-0">This reconciliation was cancelled and cannot be submitted. Its matches and brought-forward clearances were released, so the figures above are recalculated for the cancelled record without them; any difference shown is not an outstanding reconciliation difference.</div>
    @elseif(! $reconciled)
        <div class="alert alert-warning mb-0">The difference must be exactly 0.00 before the reconciliation can be submitted.
            @if($figures['unrecorded']->isNotEmpty()) Statement items not in the books total {{ $amount($figures['unrecorded_total']) }}: record them through the normal receipt or payment workflow, then match them here. Nothing is posted automatically.@endif
        </div>
    @endif
    @if($figures['reversal_pairs']->isNotEmpty())<p class="card-copy">{{ $figures['reversal_pairs']->count() }} book lines are not listed because a journal and its reversal, both unmatched, cancel each other out.</p>@endif
    <div class="row g-3 mt-1">
        <div class="col-md-3"><small>Prepared by</small><strong class="d-block">{{ $reconciliation->preparer?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Submitted by</small><strong class="d-block">{{ $reconciliation->submitter?->name ?? '—' }}</strong><span class="table-subtext">{{ $reconciliation->submitted_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $reconciliation->approver?->name ?? '—' }}</strong><span class="table-subtext">{{ $reconciliation->approved_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-3"><small>Statement</small><strong class="d-block">@if($reconciliation->statement)<a href="{{ route('bank-statements.show', $reconciliation->statement) }}">{{ $reconciliation->statement->period_from->format('d M Y') }} – {{ $reconciliation->statement->period_to->format('d M Y') }}</a>@else Entered manually @endif</strong></div>
        @if($cancellation)<div class="col-12"><div class="alert alert-danger mb-0"><strong>Cancelled</strong> by {{ $cancellation->actor?->name ?? 'Unknown' }} on {{ $cancellation->created_at->format('d M Y H:i') }}: {{ $cancellation->properties['reason'] ?? '' }}<span class="d-block small">{{ count($cancellation->properties['released_matches'] ?? []) }} match(es) released. Kept for the record only; start a new reconciliation for this account.</span></div></div>@endif
        @if($reconciliation->rejection_reason && $reconciliation->isDraft())<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned for correction:</strong> {{ $reconciliation->rejection_reason }}</div></div>@endif
    </div>
    <div class="form-actions">
        @if($editable)
            <form method="POST" action="{{ route('bank-reconciliations.submit', $reconciliation) }}" data-submit-once>@csrf<button class="btn btn-primary" @disabled(! $reconciled)>Submit for approval</button></form>
            <details class="mt-2">
                <summary class="btn btn-outline-danger btn-sm">Cancel draft</summary>
                <form method="POST" action="{{ route('bank-reconciliations.cancel', $reconciliation) }}" class="mt-2" data-submit-once>
                    @csrf
                    <label class="form-label" for="cancellation_reason">Reason for cancelling this draft</label>
                    <textarea class="form-control @error('cancellation_reason') is-invalid @enderror" id="cancellation_reason" name="cancellation_reason" rows="2" minlength="10" maxlength="2000" required>{{ old('cancellation_reason') }}</textarea>
                    @error('cancellation_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="form-text d-block mb-2">Its matches and brought-forward clearances are released. Statements and journals are not changed. This cannot be undone.</small>
                    <button class="btn btn-danger btn-sm" data-confirm="Cancel this draft reconciliation? Its matches will be released.">Confirm cancellation</button>
                </form>
            </details>
        @endif
        @if($reconciliation->status === \App\Models\BankReconciliation::SUBMITTED && $user->can('bank_reconciliation.approve'))
            @if($isMaker)
                <span class="status-badge pending">You prepared or changed this reconciliation, so another user must approve it.</span>
            @else
                <form method="POST" action="{{ route('bank-reconciliations.approve', $reconciliation) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Approve and lock this reconciliation?">Approve and lock</button></form>
                <form method="POST" action="{{ route('bank-reconciliations.reject', $reconciliation) }}" class="d-flex gap-2" data-submit-once>@csrf<input class="form-control" name="reason" placeholder="Reason for returning" aria-label="Reason for returning" required minlength="5"><button class="btn btn-outline-danger">Return</button></form>
            @endif
        @endif
        @if($reconciliation->isApproved())<span class="status-badge success">Approved and locked — matches can no longer change.</span>@endif
    </div>
</section>

@if($editable)
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Same amount, dates at most {{ \App\Services\BankReconciliation\ReconciliationCalculator::MATCH_WINDOW_DAYS }} days apart{{ $requireReference ? ', same reference' : '' }}</span><h2>Auto-match suggestions</h2></div>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('bank-reconciliations.show', [$reconciliation, 'same_reference' => $requireReference ? 0 : 1]) }}">{{ $requireReference ? 'Allow any reference' : 'Require same reference' }}</a></div>
    @if($suggestions === [])
        <x-empty-state title="No suggestions" description="No unmatched statement line has a book entry with the same amount within the date window." />
    @else
    <form method="POST" action="{{ route('bank-reconciliations.auto-match', $reconciliation) }}" data-submit-once>@csrf
        <input type="hidden" name="same_reference" value="{{ $requireReference ? 1 : 0 }}">
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Apply</th><th>Statement date</th><th>Statement description</th><th>Book date</th><th>Voucher</th><th class="text-end">Amount</th><th>Basis</th></tr></thead><tbody>
        @foreach($suggestions as $suggestion)
            <tr>
                <td><input class="form-check-input" type="checkbox" name="pairs[]" value="{{ $suggestion['statement_line']->id }}:{{ $suggestion['journal_line']->id }}" checked aria-label="Apply suggestion"></td>
                <td>{{ $suggestion['statement_line']->transaction_date->format('d M Y') }}</td>
                <td>{{ $suggestion['statement_line']->description }}<span class="table-subtext">{{ $suggestion['statement_line']->reference }}</span></td>
                <td>{{ $suggestion['journal_line']->journalEntry->transaction_date->format('d M Y') }}</td>
                <td>{{ $suggestion['journal_line']->journalEntry->voucher_number }}<span class="table-subtext">{{ $suggestion['journal_line']->journalEntry->reference_number }}</span></td>
                <td class="text-end font-monospace">{{ $amount($suggestion['statement_line']->amount) }}</td>
                <td>{{ $suggestion['same_reference'] ? 'Same reference, ' : '' }}{{ $suggestion['days_apart'] }} {{ str('day')->plural($suggestion['days_apart']) }} apart</td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="form-actions"><button class="btn btn-primary">Apply selected matches</button></div>
    </form>
    @endif
</section>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Bank charges, profit, direct credits — record them through receipts or payments; nothing is posted from here</span><h2>Statement items not in the books</h2></div><span class="status-badge {{ $figures['unrecorded']->isEmpty() ? 'success' : 'pending' }}">{{ $figures['unrecorded']->count() }}</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Amount</th>@if($editable)<th>Match to a book entry</th><th></th>@endif</tr></thead><tbody>
    @forelse($figures['unrecorded'] as $line)
        <tr>
            <td>{{ $line->transaction_date->format('d M Y') }}</td>
            <td>{{ $line->description }}</td>
            <td>{{ $line->reference }}</td>
            <td class="text-end font-monospace">{{ $amount($line->amount) }}</td>
            @if($editable)
                @php $options = $candidatesByAmount->get(\App\Services\AccountingDecimal::string(\App\Services\AccountingDecimal::of($line->amount)), collect()); @endphp
                <td>@if($options->isEmpty())<span class="table-subtext">No unmatched book entry with this amount</span>@else
                    <form method="POST" action="{{ route('bank-reconciliations.matches.store', $reconciliation) }}" class="d-flex gap-2" data-submit-once>@csrf
                        <input type="hidden" name="statement_line_id" value="{{ $line->id }}">
                        <select class="form-select form-select-sm" name="journal_line_id" aria-label="Book entry">@foreach($options as $option)<option value="{{ $option->id }}">{{ $option->journalEntry->transaction_date->format('d M Y') }} — {{ $option->journalEntry->voucher_number }} — {{ \Illuminate\Support\Str::limit($option->description ?: $option->journalEntry->narration, 40) }}</option>@endforeach</select>
                        <button class="btn btn-sm btn-primary">Match</button>
                    </form>@endif</td>
                <td><form method="POST" action="{{ route('bank-reconciliations.lines.ignore', [$reconciliation, $line]) }}" data-submit-once>@csrf<button class="btn btn-sm btn-outline-danger" data-confirm="Ignore this statement line? It stays visible under ignored lines.">Ignore</button></form></td>
            @endif
        </tr>
    @empty
        <tr><td colspan="{{ $editable ? 6 : 4 }}"><x-empty-state title="Nothing outstanding on the statement" description="Every statement line up to the statement date is matched or ignored." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

@foreach(['uncleared' => ['Uncleared deposits', 'Book debits not yet on a statement'], 'unpresented' => ['Unpresented payments', 'Book credits not yet on a statement']] as $key => [$heading, $kicker])
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $kicker }}</span><h2>{{ $heading }}</h2></div><span class="status-badge neutral">{{ $currency }} {{ $amount($figures[$key.'_total']) }}</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Voucher</th><th>Narration</th><th class="text-end">Amount</th>@if($editable && $firstStatementDate)<th>Cleared before the first imported statement</th>@endif</tr></thead><tbody>
    @forelse($figures[$key] as $line)
        <tr>
            <td>{{ $line->journalEntry->transaction_date->format('d M Y') }}</td>
            <td>{{ $line->journalEntry->voucher_number }}<span class="table-subtext">{{ $line->journalEntry->reference_number }}</span></td>
            <td>{{ $line->description ?: $line->journalEntry->narration }}</td>
            <td class="text-end font-monospace">{{ $amount($bookAmount($line)->abs()) }}</td>
            @if($editable && $firstStatementDate)
                <td>@if($line->journalEntry->transaction_date->toDateString() < \Illuminate\Support\Carbon::parse($firstStatementDate)->toDateString())
                    <form method="POST" action="{{ route('bank-reconciliations.brought-forward', $reconciliation) }}" class="d-flex gap-2" data-submit-once>@csrf
                        <input type="hidden" name="journal_line_id" value="{{ $line->id }}">
                        <input class="form-control form-control-sm" name="note" placeholder="Why it is already cleared" aria-label="Clearance note" required minlength="5">
                        <button class="btn btn-sm btn-outline-primary">Clear</button>
                    </form>@endif</td>
            @endif
        </tr>
    @empty
        <tr><td colspan="5"><x-empty-state title="None" description="No outstanding items of this kind." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endforeach

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Each statement line matches one book entry; a book entry is matched once</span><h2>Matched items</h2></div><span class="status-badge neutral">{{ $figures['matches']->count() }}</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Statement</th><th>Book entry</th><th class="text-end">Amount</th><th>Type</th><th>Matched by</th>@if($editable)<th></th>@endif</tr></thead><tbody>
    @forelse($figures['matches'] as $match)
        <tr>
            <td>@if($match->statementLine){{ $match->statementLine->transaction_date->format('d M Y') }} — {{ $match->statementLine->description }}@else<span class="table-subtext">Brought forward: {{ $match->note }}</span>@endif</td>
            <td>{{ $match->journalLine->journalEntry->transaction_date->format('d M Y') }} — {{ $match->journalLine->journalEntry->voucher_number }}</td>
            <td class="text-end font-monospace">{{ $amount($bookAmount($match->journalLine)) }}</td>
            <td>{{ str($match->match_type)->replace('_', ' ')->ucfirst() }}</td>
            <td>{{ $match->matcher?->name ?? '—' }}</td>
            @if($editable)<td><form method="POST" action="{{ route('bank-reconciliations.matches.destroy', [$reconciliation, $match]) }}" data-submit-once>@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Unmatch</button></form></td>@endif
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No matches yet" description="Apply suggestions or match statement lines manually." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

@if($figures['ignored']->isNotEmpty())
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Set aside in this reconciliation; they do not change the figures</span><h2>Ignored statement lines</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Description</th><th class="text-end">Amount</th>@if($editable)<th></th>@endif</tr></thead><tbody>
    @foreach($figures['ignored'] as $line)
        <tr>
            <td>{{ $line->transaction_date->format('d M Y') }}</td><td>{{ $line->description }}</td><td class="text-end font-monospace">{{ $amount($line->amount) }}</td>
            @if($editable)<td><form method="POST" action="{{ route('bank-reconciliations.lines.restore', [$reconciliation, $line]) }}" data-submit-once>@csrf<button class="btn btn-sm btn-outline-primary">Restore</button></form></td>@endif
        </tr>
    @endforeach
    </tbody></table></div>
</section>
@endif
@endsection
