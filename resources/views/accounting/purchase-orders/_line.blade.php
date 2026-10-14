<tr data-line-row>
    <td><input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" maxlength="500" placeholder="Item or service" aria-label="Line description" required></td>
    <td><input class="form-control text-end amount-input" name="lines[{{ $index }}][quantity]" data-field="quantity" value="{{ $line['quantity'] ?? '' }}" inputmode="decimal" aria-label="Quantity" required></td>
    <td><input class="form-control" name="lines[{{ $index }}][unit]" value="{{ $line['unit'] ?? '' }}" maxlength="30" placeholder="pcs" aria-label="Unit"></td>
    <td><input class="form-control text-end amount-input" name="lines[{{ $index }}][unit_price]" data-field="unit_price" value="{{ $line['unit_price'] ?? '' }}" inputmode="decimal" aria-label="Unit price" required></td>
    <td><input class="form-control text-end amount-input" name="lines[{{ $index }}][discount_amount]" data-field="discount_amount" value="{{ $line['discount_amount'] ?? '0.0000' }}" inputmode="decimal" aria-label="Discount amount"></td>
    <td><input class="form-control text-end amount-input" name="lines[{{ $index }}][tax_amount]" data-field="tax_amount" value="{{ $line['tax_amount'] ?? '0.0000' }}" inputmode="decimal" aria-label="Tax amount"></td>
    <td>
        <select class="form-select mb-1" name="lines[{{ $index }}][account_id]" aria-label="Expense or asset ledger"><option value="">No ledger</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}" @selected(($line['account_id'] ?? null)==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>
        <select class="form-select" name="lines[{{ $index }}][service_id]" aria-label="Service"><option value="">No service</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(($line['service_id'] ?? null)==$service->id)>{{ $service->name }}</option>@endforeach</select>
    </td>
    <td class="text-end font-monospace" data-line-total>0.0000</td>
    <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-line aria-label="Remove line">×</button></td>
</tr>
