@props(['title', 'description'])

<div class="empty-state">
    <div class="empty-state-icon" aria-hidden="true">◇</div>
    <h3>{{ $title }}</h3>
    <p>{{ $description }}</p>
</div>
