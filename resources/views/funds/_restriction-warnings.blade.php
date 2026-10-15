{{-- Restricted-fund rule breaches of an unposted journal ($journal) or operational transaction ($transaction), e.g. after a fund's permitted ledgers changed. --}}
@inject('fundRules', 'App\Services\Funds\FundRestrictionPolicy')
@php
    $fundWarnings = isset($journal) ? $fundRules->journalWarnings($journal) : $fundRules->transactionWarnings($transaction);
@endphp
@if($fundWarnings !== [])
    <div class="alert alert-danger" role="alert">
        <strong>Restricted-fund rule.</strong>
        <ul class="mb-1">@foreach($fundWarnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
        <span>This cannot be posted until the ledger or the fund is corrected.</span>
    </div>
@endif
