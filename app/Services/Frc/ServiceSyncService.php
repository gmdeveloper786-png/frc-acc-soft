<?php

namespace App\Services\Frc;

use App\Models\FrcService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServiceSyncService
{
    public function __construct(private FrcApiClient $client, private AuditLogger $audit) {}

    public function sync(): FrcApiResult
    {
        $result = $this->client->getAll($this->client->servicesEndpoint(), perPage: 100);
        if (! $result->successful) {
            $this->audit->log('services.sync_failed', properties: [
                'state' => $result->state,
                'http_status' => $result->status,
            ]);

            return $result;
        }

        try {
            $rows = $this->rows($result->data);
            $syncedAt = now();
            $created = 0;
            $updated = 0;

            DB::transaction(function () use ($rows, $syncedAt, &$created, &$updated): void {
                foreach ($rows as $row) {
                    $service = FrcService::query()->firstOrNew(['frc_service_id' => (int) $row['id']]);
                    if ($service->exists) {
                        $updated++;
                    } else {
                        $created++;
                    }

                    $service->fill([
                        'name' => trim($row['name']),
                        'status' => $this->nullableString($row['status'] ?? null),
                        'last_synced_at' => $syncedAt,
                    ])->save();
                }
            });

            $summary = ['received' => count($rows), 'created' => $created, 'updated' => $updated];
            $this->audit->log('services.synced', properties: $summary);

            return new FrcApiResult(
                true,
                $result->status,
                $summary,
                count($rows).' services synchronized successfully.',
                FrcApiResult::SyncSuccessful,
            );
        } catch (Throwable) {
            $this->audit->log('services.sync_failed', properties: [
                'state' => FrcApiResult::InvalidResponse,
                'http_status' => $result->status,
            ]);

            return new FrcApiResult(false, $result->status, null, 'FRC service response could not be synchronized safely.', FrcApiResult::InvalidResponse);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $data): array
    {
        $rows = is_array($data) ? ($data['data'] ?? $data) : [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \UnexpectedValueException('Expected a list of services.');
        }

        $externalIds = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! isset($row['id'], $row['name'])
                || filter_var($row['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || ! is_string($row['name'])
                || trim($row['name']) === ''
                || mb_strlen($row['name']) > 255
            ) {
                throw new \UnexpectedValueException('Service row is invalid.');
            }

            $externalId = (int) $row['id'];
            if (isset($externalIds[$externalId])) {
                throw new \UnexpectedValueException('Duplicate service ID received.');
            }

            $externalIds[$externalId] = true;
        }

        return $rows;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar service field.');
        }

        return trim((string) $value);
    }
}
