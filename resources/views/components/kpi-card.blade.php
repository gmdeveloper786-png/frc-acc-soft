@props(['label', 'tone' => 'teal', 'description', 'value' => null, 'icon' => null, 'status' => null])

<article class="kpi-card">
    <div class="kpi-card-top">
        <span class="kpi-icon tone-{{ $tone }}" aria-hidden="true">{{ $icon ?: strtoupper(substr($label, 0, 1)) }}</span>
        <span class="status-badge {{ $value === null ? 'pending' : 'neutral' }}">{{ $status ?: ($value === null ? 'Awaiting data' : 'Calculated') }}</span>
    </div>
    <p class="kpi-label">{{ $label }}</p>
    <p class="kpi-value" @if($value === null) aria-label="Amount unavailable" @endif>{{ $value ?? '—' }}</p>
    <p class="kpi-description">{{ $description }}</p>
</article>
