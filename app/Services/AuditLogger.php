<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * The actor defaults to the authenticated user. Services acting for an
     * explicit user pass it, so the record names who really made the change.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, ?Model $auditable = null, array $properties = [], ?User $actor = null): AuditLog
    {
        $request = request();

        return AuditLog::query()->create([
            'actor_id' => $actor?->id ?? auth()->id(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'branch_id' => $this->branchId($auditable, $properties),
            'properties' => $this->sanitize($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => str($request?->userAgent())->limit(500)->toString() ?: null,
        ]);
    }

    /**
     * Users recorded by the immutable audit trail as having performed any of
     * these events on the record, e.g. the editors of a draft.
     *
     * @param  list<string>  $events
     * @return list<int>
     */
    public function actorIds(Model $auditable, array $events): array
    {
        return AuditLog::query()->where('auditable_type', $auditable->getMorphClass())->where('auditable_id', $auditable->getKey())
            ->whereIn('event', $events)->whereNotNull('actor_id')->distinct()->pluck('actor_id')
            ->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * Branch of the audited record, when it has one.
     *
     * @param  array<string, mixed>  $properties
     */
    private function branchId(?Model $auditable, array $properties): ?int
    {
        $branchId = $auditable instanceof Branch ? $auditable->getKey() : ($auditable?->getAttribute('branch_id') ?? $properties['branch_id'] ?? null);

        return is_numeric($branchId) && Branch::query()->whereKey($branchId)->exists() ? (int) $branchId : null;
    }

    /** @param array<string, mixed> $properties */
    private function sanitize(array $properties): array
    {
        return collect($properties)->reject(
            fn (mixed $value, string $key): bool => str($key)->lower()->contains(['password', 'token', 'secret', 'app_key']),
        )->all();
    }
}
