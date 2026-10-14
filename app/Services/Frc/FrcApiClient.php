<?php

namespace App\Services\Frc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FrcApiClient
{
    public function connectionCheck(): FrcApiResult
    {
        $result = $this->get($this->servicesEndpoint());

        if ($result->successful) {
            return new FrcApiResult(
                true,
                $result->status,
                $result->data,
                'FRC API connection is working and returned a valid response.',
                FrcApiResult::Connected,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function get(string $endpoint, array $query = []): FrcApiResult
    {
        if (! config('services.frc.enabled')) {
            return new FrcApiResult(false, null, null, 'FRC API integration is disabled.', FrcApiResult::ApiDisabled);
        }

        if (blank(config('services.frc.base_url'))) {
            return new FrcApiResult(false, null, null, 'FRC API endpoint is not configured correctly.', FrcApiResult::NotFound);
        }

        if (blank(config('services.frc.token'))) {
            return new FrcApiResult(false, null, null, 'FRC API token is missing. Configure it securely on the server.', FrcApiResult::TokenMissing);
        }

        try {
            $response = $this->send(ltrim($endpoint, '/'), $query);
        } catch (ConnectionException) {
            return new FrcApiResult(false, null, null, 'FRC API server is unreachable or the connection timed out.', FrcApiResult::ServerUnreachable);
        } catch (Throwable) {
            return new FrcApiResult(false, null, null, 'FRC API request could not be completed safely.', FrcApiResult::SyncFailed);
        }

        if ($response->successful()) {
            $data = $response->json();

            if (! is_array($data)) {
                return new FrcApiResult(false, $response->status(), null, 'FRC API returned an invalid response.', FrcApiResult::InvalidResponse);
            }

            return new FrcApiResult(true, $response->status(), $data, 'FRC API request completed successfully.', FrcApiResult::Connected);
        }

        [$state, $message] = match (true) {
            $response->status() === 401 => [FrcApiResult::Unauthorized, 'FRC API rejected the integration token (HTTP 401 Unauthorized).'],
            $response->status() === 403 => [FrcApiResult::Forbidden, 'FRC API token is valid but does not have permission for this request (HTTP 403 Forbidden).'],
            $response->status() === 404 => [FrcApiResult::NotFound, 'FRC API endpoint was not found (HTTP 404). Check the configured base URL.'],
            $response->status() === 429 => [FrcApiResult::RateLimited, 'FRC API rate limit was reached (HTTP 429). Please try again later.'],
            $response->serverError() => [FrcApiResult::ServerUnreachable, 'FRC API is temporarily unavailable.'],
            default => [FrcApiResult::SyncFailed, 'FRC API request failed with status '.$response->status().'.'],
        };

        return new FrcApiResult(false, $response->status(), null, $message, $state);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function getPaginated(string $endpoint, array $query = [], int $page = 1, int $perPage = 50): FrcApiResult
    {
        return $this->get($endpoint, [
            ...$query,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ]);
    }

    /**
     * Fetch every page while supporting both standard Laravel pagination and
     * legacy list endpoints which omit pagination metadata.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function getAll(string $endpoint, array $query = [], int $perPage = 100): FrcApiResult
    {
        $allRows = [];
        $seenPageSignatures = [];
        $page = 1;
        $maximumPages = 100;

        while ($page <= $maximumPages) {
            $result = $this->getPaginated($endpoint, $query, $page, $perPage);

            if (! $result->successful) {
                return $result;
            }

            $rows = $this->extractRows($result->data);

            if ($rows === null) {
                return new FrcApiResult(false, $result->status, null, 'FRC API returned an invalid list response.', FrcApiResult::InvalidResponse);
            }

            if ($rows === []) {
                break;
            }

            $signature = hash('sha256', json_encode(array_column($rows, 'id'), JSON_THROW_ON_ERROR));
            if (isset($seenPageSignatures[$signature])) {
                break;
            }

            $seenPageSignatures[$signature] = true;
            array_push($allRows, ...$rows);

            $lastPage = $this->lastPage($result->data);
            if ($lastPage !== null && $page >= $lastPage) {
                break;
            }

            $page++;
        }

        if ($page > $maximumPages) {
            return new FrcApiResult(false, 200, null, 'FRC API pagination exceeded the safe page limit.', FrcApiResult::InvalidResponse);
        }

        return new FrcApiResult(true, 200, ['data' => $allRows], 'FRC API list retrieved successfully.', FrcApiResult::Connected);
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.frc.base_url'), '/').'/')
            ->acceptJson()
            ->timeout((int) config('services.frc.timeout', 15))
            ->connectTimeout((int) config('services.frc.connect_timeout', 5))
            ->retry(
                max(1, (int) config('services.frc.retry_times', 2) + 1),
                (int) config('services.frc.retry_delay_ms', 200),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );

        $token = (string) config('services.frc.token');

        return filled($token) ? $request->withToken($token) : $request;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    protected function send(string $endpoint, array $query): Response
    {
        return $this->request()->get($endpoint, $query);
    }

    public function branchesEndpoint(): string
    {
        return ltrim((string) config('services.frc.branches_endpoint'), '/');
    }

    public function servicesEndpoint(): string
    {
        return ltrim((string) config('services.frc.services_endpoint'), '/');
    }

    /** @return list<array<string, mixed>>|null */
    private function extractRows(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $rows = $payload['data'] ?? $payload;
        if (is_array($rows) && isset($rows['data'])) {
            $rows = $rows['data'];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }
        }

        return $rows;
    }

    private function lastPage(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $meta = $payload['meta'] ?? ($payload['data']['meta'] ?? null);
        if (! is_array($meta) || ! isset($meta['last_page']) || ! is_numeric($meta['last_page'])) {
            return null;
        }

        return max(1, (int) $meta['last_page']);
    }
}
