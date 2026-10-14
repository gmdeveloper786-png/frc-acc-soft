{{-- Policy and readiness banners shared by every fee posting screen. --}}
@if(! $feeSettings['chart_exists'])
    <div class="alert alert-danger"><strong>No approved Chart of Accounts.</strong> Fee posting cannot run until the approved Chart of Accounts is imported and the fee income, receivable and cash/bank ledgers exist.</div>
@endif
@if(! $feeSettings['policy_approved'])
    <div class="alert alert-warning">
        <strong>Fee posting is blocked.</strong> The fee accounting policy has not been approved by the Director (<code>fees.accounting_policy_approved</code> = 0). Previews are shown for checking only; nothing can be posted.
        @if($feeSettings['unapproved_default'])
            <span class="d-block mt-1">The current basis <strong>cash</strong> is only the proposed default; it has not been approved by the client.</span>
        @else
            <span class="d-block mt-1">The current basis is <strong>{{ $feeSettings['basis'] }}</strong>; it still awaits approval.</span>
        @endif
    </div>
@else
    <div class="alert alert-info mb-3">Fee accounting basis: <strong>{{ $feeSettings['basis'] }}</strong> (approved).</div>
@endif
@if(session('posting_results'))
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Posting results</h2><ul class="mb-0">@foreach(session('posting_results') as $id => $result)<li><span class="status-badge {{ $result['status'] === 'posted' ? 'success' : 'pending' }}">{{ $result['status'] }}</span> #{{ $id }} — {{ $result['message'] }}</li>@endforeach</ul></div></div>
@endif
