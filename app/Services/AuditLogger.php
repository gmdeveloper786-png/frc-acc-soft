<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string, mixed> $properties */
    public function log(string $event, ?Model $auditable = null, array $properties = []): AuditLog
    {
        $request = request();

        return AuditLog::query()->create([
            'actor_id' => auth()->id(),
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
