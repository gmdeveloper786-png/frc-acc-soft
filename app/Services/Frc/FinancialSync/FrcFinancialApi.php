<?php

namespace App\Services\Frc\FinancialSync;

use App\Services\Frc\FrcApiClient;
use App\Services\Frc\FrcApiResult;
use Illuminate\Support\Carbon;

/**
 * Reads one cursor page of the proposed FRC `enrollments` or `payments`
 * endpoint through the existing client (token, timeouts and retries). It
 * validates only the envelope; records are validated by FrcRecordNormalizer.
 */
class FrcFinancialApi
{
    public const string Enrollments = 'enrollments';

    public const string Payments = 'payments';

    public const array ENTITIES = [self::Enrollments, self::Payments];

    public const array DEFAULT_ENDPOINTS = [
        self::Enrollments => 'integrations/accounting/v1/enrollments',
        self::Payments => 'integrations/accounting/v1/payments',
    ];

    public const int PER_PAGE = 100;

    public function __construct(private FrcApiClient $client) {}

    /** Endpoint path, overridable with `services.frc.{entity}_endpoint`. */
    public function endpoint(string $entity): string
    {
        return ltrim((string) (config('services.frc.'.$entity.'_endpoint') ?: self::DEFAULT_ENDPOINTS[$entity]), '/');
    }

    public function fetchPage(string $entity, ?Carbon $updatedSince, ?int $afterId): FrcPage
    {
        $query = ['per_page' => self::PER_PAGE];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince->toIso8601String();
            if ($afterId !== null) {
                $query['after_id'] = $afterId;
            }
        }

        $result = $this->client->get($this->endpoint($entity), $query);
        if (! $result->successful) {
            return FrcPage::failed($this->failureMessage($entity, $result), $result->state, $result->status);
        }

        $payload = $result->data;
        $records = $payload['data'] ?? null;
        $meta = $payload['meta'] ?? null;
        if (! is_array($records) || ! array_is_list($records) || ! is_array($meta) || ! array_key_exists('has_more', $meta) || ! is_bool($meta['has_more'])) {
            return FrcPage::failed('FRC returned a malformed '.$entity.' page (expected "data" and "meta.has_more"). Nothing from this page was imported.', FrcApiResult::InvalidResponse, $result->status);
        }

        $nextSince = null;
        if (isset($meta['next_updated_since'])) {
            try {
                $nextSince = is_string($meta['next_updated_since'])
                    ? Carbon::parse($meta['next_updated_since'])->setTimezone((string) config('app.timezone'))
                    : null;
            } catch (\Throwable) {
                $nextSince = null;
            }
            if ($nextSince === null) {
                return FrcPage::failed('FRC returned an unreadable '.$entity.' cursor (meta.next_updated_since).', FrcApiResult::InvalidResponse, $result->status);
            }
        }
        $nextAfterId = isset($meta['next_after_id']) && filter_var($meta['next_after_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false
            ? (int) $meta['next_after_id'] : null;

        return new FrcPage(true, $records, $meta['has_more'], $nextSince, $nextAfterId);
    }

    /** Fixed, sanitised failure text: never the response body or the token. */
    private function failureMessage(string $entity, FrcApiResult $result): string
    {
        return match ($result->state) {
            FrcApiResult::NotFound => $result->status === 404
                ? 'FRC returned HTTP 404 for the '.$entity.' endpoint. The FRC payments and enrollments endpoints are a proposal awaiting approval; nothing was imported.'
                : $result->message,
            FrcApiResult::Forbidden => 'FRC returned HTTP 403 for the '.$entity.' endpoint: the integration token does not have the '.$entity.' read ability yet (endpoints awaiting approval).',
            default => $result->message,
        };
    }
}
