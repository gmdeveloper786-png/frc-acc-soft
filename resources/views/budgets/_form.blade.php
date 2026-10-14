@php
    $budget = $budget ?? null;
    $isRevision = $budget && $budget->root_budget_id !== null;
    $gridErrors = collect(['lines_json', 'lines', 'annual_amount'])->flatMap(fn ($key) => $errors->get($key))
        ->merge(collect($errors->get('lines.*'))->flatten())->unique();
    // Everything the grid needs travels as one JSON document; the grid inputs carry no name and are never posted one by one.
    $gridData = [
        'periods' => $periods->map(fn ($period) => ['id' => $period->id, 'name' => $period->name])->values()->all(),
        'ledgers' => $ledgers->map(fn ($ledger) => ['id' => $ledger->id, 'code' => $ledger->code, 'name' => $ledger->name, 'type' => ucfirst($ledger->account_type->value)])->values()->all(),
        'lines' => $gridLines,
    ];
@endphp
@foreach(['status','fiscal_year_id'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
<form method="POST" action="{{ $budget ? route('budgets.update',$budget) : route('budgets.store') }}" data-submit-once data-budget-form>@csrf @if($budget) @method('PUT') @endif
<input type="hidden" name="fiscal_year_id" value="{{ $year->id }}">
{{-- Filled by the script below with the complete set of lines just before the form is sent. --}}
<input type="hidden" name="lines_json" value="" data-budget-payload>
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $budget ? 'Version '.$budget->version : 'Version 1' }} · {{ $year->name }}</span><h2>Budget details</h2></div>@if($budget)<span class="status-badge {{ $budget->status->badge() }}">{{ $budget->status->label() }}</span>@endif</div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="name">Budget name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="150" value="{{ old('name',$budget?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label>
            @if($isRevision)
                <input type="hidden" name="branch_id" value="{{ $budget->branch_id }}"><input class="form-control" id="branch_id" value="{{ $budget->branch->name }}" disabled>
            @else
                <select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id',$budget?->branch_id)==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif
        </div>
        <div class="col-md-4"><label class="form-label" for="service_id">Service</label>
            @if($isRevision)
                <input type="hidden" name="service_id" value="{{ $budget->service_id }}"><input class="form-control" id="service_id" value="{{ $budget->service?->name ?? 'Whole branch' }}" disabled>
            @else
                <select class="form-select @error('service_id') is-invalid @enderror" id="service_id" name="service_id"><option value="">Whole branch (all services)</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(old('service_id',$budget?->service_id)==$service->id)>{{ $service->name }}</option>@endforeach</select>@error('service_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif
        </div>
        @if($isRevision)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Revision reason:</strong> {{ $budget->revision_reason }}. A revision keeps the fiscal year, branch and service of the budget it replaces.</div></div>@endif
        @if($budget?->rejection_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned for correction:</strong> {{ $budget->rejection_reason }}</div></div>@endif
    </div>
</section>

<section class="surface-card" data-budget-grid data-spread-url="{{ route('budgets.spread') }}" data-fiscal-year="{{ $year->id }}" data-page-size="25">
    <div class="card-heading"><div><span class="section-kicker">One row per ledger, one amount per period · exact 4-decimal amounts</span><h2>Budget lines</h2></div><span class="status-badge neutral" data-budget-summary>0 ledgers</span></div>
    @if($gridErrors->isNotEmpty())<div class="alert alert-danger">@foreach($gridErrors as $message)<span class="d-block">{{ $message }}</span>@endforeach</div>@endif
    @if($ledgers->isEmpty())<div class="alert alert-warning">No active, postable income or expense ledger exists yet. Budgets can only be set on such ledgers.</div>@endif
    <noscript><div class="alert alert-danger">This form needs JavaScript to send the budget lines. Without it the budget is not saved.</div></noscript>
    <div class="row g-2 align-items-end mb-3">
        <div class="col-lg-4"><label class="form-label" for="budget_ledger_picker">Add a ledger</label>
            <select class="form-select" id="budget_ledger_picker" data-budget-picker><option value="">Select ledger</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}">{{ $ledger->code }} — {{ $ledger->name }} ({{ ucfirst($ledger->account_type->value) }})</option>@endforeach</select></div>
        <div class="col-lg-4 d-flex gap-2"><button class="btn btn-outline-primary" type="button" data-budget-add>+ Add account</button><button class="btn btn-outline-primary" type="button" data-budget-add-all>Add all ledgers</button></div>
        <div class="col-lg-4"><label class="form-label" for="budget_search">Find in this budget</label><input class="form-control" id="budget_search" type="search" placeholder="Ledger code or name" autocomplete="off" data-budget-search></div>
    </div>
    <div class="alert alert-warning d-none" role="alert" data-budget-message></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Ledger</th><th>Spread annual amount</th>@foreach($periods as $period)<th class="text-end">{{ $period->name }}</th>@endforeach<th class="text-end">Row total</th><th></th></tr></thead>
    <tbody data-budget-body></tbody>
    <tfoot><tr><th colspan="{{ $periods->count() + 2 }}" class="text-end">Budget total, all ledgers ({{ $appSettings['currency_code'] }})</th><th class="text-end font-monospace" data-budget-total>0.0000</th><th></th></tr></tfoot></table></div>
    <div class="d-flex align-items-center gap-2 mt-2">
        <button class="btn btn-sm btn-outline-primary" type="button" data-budget-prev>Previous</button>
        <span class="table-subtext d-inline" data-budget-page>Page 1 of 1</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-budget-next>Next</button>
    </div>
    <p class="card-copy">Ledgers are shown a page at a time; every page and every ledger hidden by the filter stays part of the budget and is saved together. Enter an annual amount and press Spread to divide it equally over the periods on the server; the last period carries the rounding remainder so the row total is exact. Leave a cell blank for no allocation. Totals shown here are a preview; the server recalculates them on save. A budget creates no accounting entry.</p>
    <div class="form-actions"><button class="btn btn-primary">{{ $budget ? 'Save draft changes' : 'Save draft' }}</button><a class="btn btn-outline-primary" href="{{ $budget ? route('budgets.show',$budget) : route('budgets.index') }}">Cancel</a></div>
</section>
</form>
<script type="application/json" data-budget-data>{!! json_encode($gridData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const grid = document.querySelector('[data-budget-grid]');
    if (! grid) return;
    const form = grid.closest('form');
    const data = JSON.parse(document.querySelector('[data-budget-data]').textContent);
    const body = grid.querySelector('[data-budget-body]');
    const messageBox = grid.querySelector('[data-budget-message]');
    const search = grid.querySelector('[data-budget-search]');
    const picker = grid.querySelector('[data-budget-picker]');
    const pageSize = Number(grid.dataset.pageSize);
    const ledgers = new Map(data.ledgers.map((ledger) => [ledger.id, ledger]));
    // The whole budget lives here, not in the DOM: only one page of it is ever rendered.
    const rows = new Map();
    const rowTotals = new Map();
    let page = 1;

    // Exact preview arithmetic in ten-thousandths; a blank cell counts as nothing.
    const units = (value) => {
        const text = String(value ?? '').trim();
        if (text === '') return 0n;
        const match = text.match(/^(\d{1,15})(?:\.(\d{1,4}))?$/);
        return match ? BigInt(match[1]) * 10000n + BigInt((match[2] || '').padEnd(4, '0')) : null;
    };
    const format = (value) => value === null ? 'Invalid' : `${value / 10000n}.${String(value % 10000n).padStart(4, '0')}`;
    const label = (accountId) => {
        const ledger = ledgers.get(accountId);
        return ledger ? `${ledger.code} — ${ledger.name} (${ledger.type})` : `Account #${accountId} — no longer an active budgetable ledger; remove it`;
    };
    const sortKey = (accountId) => ledgers.get(accountId)?.code ?? `~${accountId}`;
    const ordered = () => [...rows.keys()].sort((a, b) => sortKey(a).localeCompare(sortKey(b)));
    const visible = () => {
        const term = search.value.trim().toLowerCase();
        return ordered().filter((accountId) => term === '' || label(accountId).toLowerCase().includes(term));
    };
    const ensureRow = (accountId) => {
        if (! rows.has(accountId)) rows.set(accountId, { annual: '', amounts: new Map() });
        return rows.get(accountId);
    };
    const rowTotal = (accountId) => {
        let total = 0n;
        for (const amount of rows.get(accountId).amounts.values()) {
            const value = units(amount);
            if (value === null) return null;
            total += value;
        }
        return total;
    };
    const showMessage = (text) => {
        messageBox.textContent = text;
        messageBox.classList.toggle('d-none', text === '');
    };
    const refreshTotals = () => {
        let total = 0n;
        let lines = 0;
        for (const [accountId, row] of rows) {
            const value = rowTotals.get(accountId);
            total = total === null || value === null ? null : total + value;
            for (const amount of row.amounts.values()) if (String(amount).trim() !== '') lines++;
        }
        grid.querySelector('[data-budget-total]').textContent = format(total);
        grid.querySelector('[data-budget-summary]').textContent = `${rows.size} ledgers · ${lines} lines`;
    };
    const element = (tag, properties = {}, children = []) => {
        const node = Object.assign(document.createElement(tag), properties);
        node.append(...children);
        return node;
    };
    const render = () => {
        const list = visible();
        const pages = Math.max(1, Math.ceil(list.length / pageSize));
        page = Math.min(Math.max(1, page), pages);
        body.replaceChildren(...list.slice((page - 1) * pageSize, page * pageSize).map((accountId) => {
            const row = rows.get(accountId);
            const annual = element('input', { className: 'form-control text-end amount-input', value: row.annual, inputMode: 'decimal', placeholder: 'Annual', ariaLabel: 'Annual amount to spread' });
            annual.dataset.budgetAnnual = '';
            const spread = element('button', { className: 'btn btn-outline-primary', type: 'button', textContent: 'Spread' });
            spread.dataset.budgetSpread = '';
            const remove = element('button', { className: 'btn btn-sm btn-outline-danger', type: 'button', textContent: '×', ariaLabel: 'Remove account row' });
            remove.dataset.budgetRemove = '';
            const cells = data.periods.map((period) => {
                const value = row.amounts.get(period.id) ?? '';
                const input = element('input', { className: 'form-control text-end amount-input', value, inputMode: 'decimal', ariaLabel: `${period.name} amount` });
                input.dataset.budgetAmount = String(period.id);
                input.classList.toggle('is-invalid', units(value) === null);
                const cell = element('td', {}, [input]);
                cell.style.minWidth = '8.5rem';
                return cell;
            });
            const ledgerCell = element('td', { textContent: label(accountId) });
            ledgerCell.style.minWidth = '16rem';
            const spreadCell = element('td', {}, [element('div', { className: 'input-group' }, [annual, spread])]);
            spreadCell.style.minWidth = '13rem';
            const total = element('td', { className: 'text-end font-monospace', textContent: format(rowTotals.get(accountId)) });
            total.dataset.budgetRowTotal = '';
            const tr = element('tr', {}, [ledgerCell, spreadCell, ...cells, total, element('td', {}, [remove])]);
            tr.dataset.budgetRow = String(accountId);
            return tr;
        }));
        if (list.length === 0) {
            body.append(element('tr', {}, [element('td', { colSpan: data.periods.length + 4, textContent: rows.size === 0 ? 'No ledger added yet. Add a ledger, or add all ledgers, to start the budget.' : 'No ledger of this budget matches the filter. Hidden ledgers are still part of the budget.' })]));
        }
        grid.querySelector('[data-budget-page]').textContent = `Page ${page} of ${pages} · ${list.length} of ${rows.size} ledgers match the filter`;
        grid.querySelector('[data-budget-prev]').disabled = page <= 1;
        grid.querySelector('[data-budget-next]').disabled = page >= pages;
        refreshTotals();
    };
    const showLedger = (accountId) => {
        search.value = '';
        page = Math.floor(ordered().indexOf(accountId) / pageSize) + 1;
        render();
    };
    // The complete budget, visible or not, as the document the server expects.
    const payload = () => {
        const lines = [];
        for (const accountId of ordered()) {
            for (const [periodId, amount] of rows.get(accountId).amounts) {
                if (String(amount).trim() !== '') lines.push({ account_id: accountId, period_id: periodId, amount: String(amount).trim() });
            }
        }
        return lines;
    };

    data.lines.forEach((line) => ensureRow(line.account_id).amounts.set(line.period_id, line.amount));
    rows.forEach((row, accountId) => rowTotals.set(accountId, rowTotal(accountId)));

    grid.querySelector('[data-budget-add]').addEventListener('click', () => {
        const accountId = Number(picker.value);
        if (! accountId) return showMessage('Select a ledger to add.');
        showMessage(rows.has(accountId) ? 'That ledger is already in this budget; it is shown below.' : '');
        ensureRow(accountId);
        rowTotals.set(accountId, rowTotal(accountId));
        picker.value = '';
        showLedger(accountId);
    });
    grid.querySelector('[data-budget-add-all]').addEventListener('click', () => {
        ledgers.forEach((ledger, accountId) => {
            ensureRow(accountId);
            rowTotals.set(accountId, rowTotal(accountId));
        });
        showMessage('');
        search.value = '';
        page = 1;
        render();
    });
    search.addEventListener('input', () => { page = 1; render(); });
    search.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
    grid.querySelector('[data-budget-prev]').addEventListener('click', () => { page--; render(); });
    grid.querySelector('[data-budget-next]').addEventListener('click', () => { page++; render(); });

    body.addEventListener('input', (event) => {
        const tr = event.target.closest('[data-budget-row]');
        if (! tr) return;
        const accountId = Number(tr.dataset.budgetRow);
        const row = rows.get(accountId);
        if ('budgetAnnual' in event.target.dataset) {
            row.annual = event.target.value;
            return;
        }
        const periodId = Number(event.target.dataset.budgetAmount);
        if (event.target.value.trim() === '') row.amounts.delete(periodId); else row.amounts.set(periodId, event.target.value);
        event.target.classList.toggle('is-invalid', units(event.target.value) === null);
        rowTotals.set(accountId, rowTotal(accountId));
        tr.querySelector('[data-budget-row-total]').textContent = format(rowTotals.get(accountId));
        refreshTotals();
    });
    body.addEventListener('keydown', (event) => { if (event.key === 'Enter' && event.target.tagName === 'INPUT') event.preventDefault(); });
    body.addEventListener('click', async (event) => {
        const tr = event.target.closest('[data-budget-row]');
        if (! tr) return;
        const accountId = Number(tr.dataset.budgetRow);
        if (event.target.closest('[data-budget-remove]')) {
            rows.delete(accountId);
            rowTotals.delete(accountId);
            return render();
        }
        const button = event.target.closest('[data-budget-spread]');
        if (! button) return;
        button.disabled = true;
        try {
            const response = await fetch(grid.dataset.spreadUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value },
                body: JSON.stringify({ fiscal_year_id: Number(grid.dataset.fiscalYear), annual_amount: rows.get(accountId).annual.trim() }),
            });
            const result = await response.json().catch(() => null);
            if (! response.ok || ! result?.amounts) {
                showMessage(Object.values(result?.errors ?? {}).flat()[0] ?? result?.message ?? 'The annual amount could not be spread. Nothing was changed.');
                return;
            }
            rows.get(accountId).amounts = new Map(result.amounts.map((entry) => [entry.period_id, entry.amount]));
            rowTotals.set(accountId, rowTotal(accountId));
            showMessage('Annual amount spread equally over the periods; the last period carries the rounding remainder. Review and save the budget.');
            render();
        } catch (error) {
            showMessage('The annual amount could not be spread because the server could not be reached. Nothing was changed.');
        } finally {
            button.disabled = false;
        }
    });

    // Capture phase: the payload is in place, or the submit is stopped, before any other submit handler runs.
    document.addEventListener('submit', (event) => {
        if (event.target !== form) return;
        const invalid = ordered().find((accountId) => rowTotals.get(accountId) === null);
        if (invalid !== undefined) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showLedger(invalid);
            showMessage(`${label(invalid)} has an invalid amount. Enter non-negative amounts with at most four decimals.`);
            return;
        }
        form.querySelector('[data-budget-payload]').value = JSON.stringify(payload());
    }, true);

    render();
});
</script>
