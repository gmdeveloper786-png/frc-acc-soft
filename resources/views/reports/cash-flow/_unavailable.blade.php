{{-- Honest empty states shared by the cash statements. $statement may be null. --}}
@if(! $ready)
    <section class="surface-card">
        @if(! $allBranches && ! $branch)
            <x-empty-state title="No branch assigned" description="You are not assigned to any branch, so there is no cash or bank activity you may view." />
        @else
            <x-empty-state title="Select a period" description="Choose a fiscal year or enter a date range to produce the statement. No fiscal year is marked as current yet." />
        @endif
    </section>
@elseif($statement['state'] === 'setup_required')
    <section class="surface-card"><x-empty-state title="Accounting setup required" description="The Chart of Accounts is empty. The statement is produced from posted journals only, so nothing can be shown until the chart is imported and journals are posted." /></section>
@elseif($statement['state'] === 'no_cash_ledgers')
    <section class="surface-card"><x-empty-state title="Accounting setup required" description="No cash, bank or petty cash ledger is configured. Add cash and bank accounts under Cash and Bank before a cash statement can be produced." /></section>
@endif
