<?php

use App\Services\Frc\FrcApiClient;
use App\Services\Frc\FrcApiResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.frc.enabled', true);
    config()->set('services.frc.base_url', 'http://frc.test/api');
    config()->set('services.frc.token', 'integration-secret-token');
    config()->set('services.frc.branches_endpoint', 'integrations/accounting/v1/branches');
    config()->set('services.frc.services_endpoint', 'integrations/accounting/v1/services');
    config()->set('services.frc.retry_times', 0);
    config()->set('services.frc.retry_delay_ms', 0);
});

test('missing api token is reported without sending a request', function () {
    config()->set('services.frc.token');
    Http::fake();

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe(FrcApiResult::TokenMissing)
        ->and($result->message)->toBe('FRC API token is missing. Configure it securely on the server.');
    Http::assertNothingSent();
});

test('api client sends bearer token and json accept header server side', function () {
    Http::fake(['http://frc.test/api/branches*' => Http::response(['data' => [['id' => 7, 'name' => 'Main Branch']]], 200)]);

    $result = app(FrcApiClient::class)->getPaginated('branches', page: 2, perPage: 25);

    expect($result->successful)->toBeTrue()
        ->and($result->state)->toBe(FrcApiResult::Connected)
        ->and($result->status)->toBe(200)
        ->and($result->data['data'][0]['id'])->toBe(7);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer integration-secret-token')
        && $request->hasHeader('Accept', 'application/json')
        && $request['page'] === 2
        && $request['per_page'] === 25);
});

test('api client distinguishes unauthorized and forbidden responses safely', function (int $status, string $state, string $message) {
    Http::fake(['*' => Http::response(['message' => 'Token integration-secret-token rejected'], $status)]);

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe($state)
        ->and($result->message)->toBe($message)
        ->and($result->message)->not->toContain('integration-secret-token')
        ->and($result->data)->toBeNull();
})->with([
    [401, FrcApiResult::Unauthorized, 'FRC API rejected the integration token (HTTP 401 Unauthorized).'],
    [403, FrcApiResult::Forbidden, 'FRC API token is valid but does not have permission for this request (HTTP 403 Forbidden).'],
]);

test('api client reports wrong endpoints', function () {
    Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->state)->toBe(FrcApiResult::NotFound)
        ->and($result->status)->toBe(404)
        ->and($result->message)->toContain('endpoint was not found');
});

test('api client reports rate limiting', function () {
    Http::fake(['*' => Http::response(['message' => 'Too many requests'], 429)]);

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->state)->toBe(FrcApiResult::RateLimited)
        ->and($result->status)->toBe(429);
});

test('api client rejects invalid successful responses', function () {
    Http::fake(['*' => Http::response('<html>not json</html>', 200, ['Content-Type' => 'text/html'])]);

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe(FrcApiResult::InvalidResponse)
        ->and($result->data)->toBeNull();
});

test('api client handles connection failures and timeouts safely', function () {
    $client = new class extends FrcApiClient
    {
        protected function send(string $endpoint, array $query): Response
        {
            throw new ConnectionException('Timeout with integration-secret-token');
        }
    };

    $result = $client->connectionCheck();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe(FrcApiResult::ServerUnreachable)
        ->and($result->status)->toBeNull()
        ->and($result->message)->toBe('FRC API server is unreachable or the connection timed out.')
        ->and($result->message)->not->toContain('integration-secret-token');
});

test('disabled api integration does not send a request', function () {
    config()->set('services.frc.enabled', false);
    Http::fake();

    $result = app(FrcApiClient::class)->connectionCheck();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe(FrcApiResult::ApiDisabled)
        ->and($result->message)->toBe('FRC API integration is disabled.');
    Http::assertNothingSent();
});

test('api client follows legacy pagination until an empty page', function () {
    Http::fake(function (Request $request) {
        $page = (int) ($request['page'] ?? 1);

        return Http::response(['data' => match ($page) {
            1 => [['id' => 1, 'name' => 'One']],
            2 => [['id' => 2, 'name' => 'Two']],
            default => [],
        }]);
    });

    $result = app(FrcApiClient::class)->getAll('branches');

    expect($result->successful)->toBeTrue()
        ->and($result->data['data'])->toHaveCount(2)
        ->and(array_column($result->data['data'], 'id'))->toBe([1, 2]);
    Http::assertSentCount(3);
});

test('api client stops safely when an unpaginated endpoint repeats data', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 1, 'name' => 'One']]])]);

    $result = app(FrcApiClient::class)->getAll('integrations/accounting/v1/services');

    expect($result->successful)->toBeTrue()
        ->and($result->data['data'])->toHaveCount(1);
    Http::assertSentCount(2);
});
