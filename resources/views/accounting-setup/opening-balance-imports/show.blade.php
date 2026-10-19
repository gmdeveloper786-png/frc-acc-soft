@extends('layouts.app')

@section('title', 'Opening Import Preview')
@section('eyebrow', 'Opening Balances')
@section('page-title', 'Opening Import Preview')
@section('page-description', $openingBalanceImport->original_filename.' · '.$openingBalanceImport->branch->name)

@section('page-actions')
    <a class="btn btn-outline-primary" href="{{ route('opening-balance-imports.create') }}">Back</a>
@endsection

@section('content')
    <div class="kpi-grid">
        <x-kpi-card label="Total debit" value="{{ number_format((float) $openingBalanceImport->total_debit, 2) }}" icon="Dr" tone="navy" description="Validated rows" />
        <x-kpi-card label="Total credit" value="{{ number_format((float) $openingBalanceImport->total_credit, 2) }}" icon="Cr" tone="teal" description="Validated rows" />
        <x-kpi-card label="Difference" value="{{ number_format((float) $openingBalanceImport->difference, 2) }}" icon="Δ" tone="amber" description="Must be exactly zero" />
        <x-kpi-card label="Invalid rows" value="{{ $openingBalanceImport->rows->filter(fn ($row) => ! empty($row->validation_errors))->count() }}" icon="!" tone="rose" description="Must be corrected" />
    </div>

    <section class="surface-card">
        <div class="card-heading">
            <h2>Preview rows</h2>
            <span class="status-badge neutral">{{ ucfirst($openingBalanceImport->status) }}</span>
        </div>

        @if (! $displayAmounts['source_available'])
            <div class="alert alert-warning" role="alert">
                The original workbook is unavailable or has changed. Exact invalid input values cannot be reconstructed from this saved preview; please upload the file again.
            </div>
        @endif

        <div class="table-responsive">
            <table class="table accounting-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Account code</th>
                        <th>Account</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>Reference</th>
                        <th>Validation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($openingBalanceImport->rows as $row)
                        @php($amounts = $displayAmounts['rows'][$row->row_number] ?? ['debit' => $row->debit, 'credit' => $row->credit])
                        <tr>
                            <td>{{ $row->row_number }}</td>
                            <td><code>{{ $row->account_code }}</code></td>
                            <td>{{ $row->account?->name ?? '—' }}</td>
                            <td>{{ $amounts['debit'] }}</td>
                            <td>{{ $amounts['credit'] }}</td>
                            <td>{{ $row->source_reference ?? '—' }}</td>
                            <td>
                                @forelse ($row->validation_errors ?? [] as $error)
                                    <span class="d-block text-danger small">{{ $error }}</span>
                                @empty
                                    <span class="text-success small">Valid</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($openingBalanceImport->status === 'preview')
            <div class="form-actions">
                <form method="POST" action="{{ route('opening-balance-imports.apply', $openingBalanceImport) }}">
                    @csrf
                    <button class="btn btn-primary" @disabled((float) $openingBalanceImport->difference !== 0.0 || $openingBalanceImport->rows->contains(fn ($row) => ! empty($row->validation_errors)))>
                        Apply to draft preparation
                    </button>
                </form>
            </div>
        @endif
    </section>
@endsection
