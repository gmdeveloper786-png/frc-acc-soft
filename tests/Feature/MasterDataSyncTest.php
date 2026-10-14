<?php

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FrcService;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Frc\BranchSyncService;
use App\Services\Frc\FrcApiClient;
use App\Services\Frc\FrcApiResult;
use App\Services\Frc\ServiceSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.frc.enabled', true);
    config()->set('services.frc.base_url', 'http://frc.test/api');
    config()->set('services.frc.token', 'secret-token');
    config()->set('services.frc.branches_endpoint', 'integrations/accounting/v1/branches');
    config()->set('services.frc.services_endpoint', 'integrations/accounting/v1/services');
    config()->set('services.frc.retry_times', 0);
});

test('branch sync upserts actual api fields without duplicates and preserves assignments', function () {
    $branch = Branch::factory()->create(['frc_branch_id' => 11, 'name' => 'Old']);
    $user = User::factory()->create();
    $branch->users()->attach($user);
    Http::fake(['*/branches*' => Http::response(['data' => [['id' => 11, 'name' => 'North', 'city' => 'Karachi', 'address' => 'A', 'phone' => '1', 'status' => 'publish']]])]);

    $first = app(BranchSyncService::class)->sync();
    $second = app(BranchSyncService::class)->sync();

    expect($first->state)->toBe(FrcApiResult::SyncSuccessful)
        ->and($second->successful)->toBeTrue()
        ->and(Branch::query()->count())->toBe(1)
        ->and($branch->fresh()->name)->toBe('North')
        ->and($branch->fresh()->users()->whereKey($user)->exists())->toBeTrue()
        ->and($branch->fresh()->last_synced_at)->not->toBeNull();
});

test('service sync preserves mapping and prevents duplicates', function () {
    FrcService::query()->create(['frc_service_id' => 22, 'name' => 'Old', 'revenue_account_id' => 999]);
    Http::fake(['*/integrations/accounting/v1/services*' => Http::response(['data' => [['id' => 22, 'name' => 'School Readiness Program', 'status' => 'publish']]])]);

    app(ServiceSyncService::class)->sync();
    app(ServiceSyncService::class)->sync();

    expect(FrcService::query()->count())->toBe(1)
        ->and(FrcService::first()->name)->toBe('School Readiness Program')
        ->and(FrcService::first()->revenue_account_id)->toBe(999)
        ->and(FrcService::first()->last_synced_at)->not->toBeNull();
});

test('filtered service response does not deactivate a missing local reference', function () {
    $existing = FrcService::factory()->create(['frc_service_id' => 23, 'status' => 'publish']);
    Http::fake(['*/integrations/accounting/v1/services*' => Http::response(['data' => [['id' => 22, 'name' => 'Published Service', 'status' => 'publish']]])]);

    app(ServiceSyncService::class)->sync();

    expect($existing->fresh()->status)->toBe('publish')
        ->and(FrcService::query()->count())->toBe(2);
});

test('sync surfaces api 401 safely and stores no mirror data or secret logs', function () {
    Http::fake(['*' => Http::response(['message' => 'secret-token'], 401)]);

    $result = app(BranchSyncService::class)->sync();
    $audit = AuditLog::query()->where('event', 'branches.sync_failed')->firstOrFail();

    expect($result->state)->toBe(FrcApiResult::Unauthorized)
        ->and($result->message)->not->toContain('secret-token')
        ->and(Branch::query()->count())->toBe(0)
        ->and(json_encode($audit->properties))->not->toContain('secret-token')
        ->and($audit->properties)->toMatchArray(['state' => FrcApiResult::Unauthorized, 'http_status' => 401]);
});

test('sync rejects invalid rows without partial writes', function () {
    Http::fake(['*/branches*' => Http::response(['data' => [
        ['id' => 11, 'name' => 'Valid'],
        ['id' => 12, 'name' => ''],
    ]])]);

    $result = app(BranchSyncService::class)->sync();

    expect($result->state)->toBe(FrcApiResult::InvalidResponse)
        ->and(Branch::query()->count())->toBe(0);
});

test('sync surfaces connection timeout safely', function () {
    $client = new class extends FrcApiClient
    {
        protected function send(string $endpoint, array $query): Response
        {
            throw new ConnectionException('secret-token timeout');
        }
    };

    $result = (new ServiceSyncService($client, app(AuditLogger::class)))->sync();

    expect($result->successful)->toBeFalse()
        ->and($result->state)->toBe(FrcApiResult::ServerUnreachable)
        ->and($result->message)->not->toContain('secret-token');
});

test('master data sync routes require server side permission', function () {
    $this->seed(RolePermissionSeeder::class);
    Http::fake(['*' => Http::response(['data' => []])]);
    $unauthorized = User::factory()->create();

    $this->actingAs($unauthorized)->post(route('branches.sync'))->assertForbidden();
    $this->post(route('services.sync'))->assertForbidden();
    $this->post(route('integrations.frc.check'))->assertForbidden();

    $administrator = User::factory()->administrator()->create();
    $administrator->assignRole(SystemRole::Administrator->value);

    $this->actingAs($administrator)->post(route('branches.sync'))->assertRedirect();
    $this->post(route('services.sync'))->assertRedirect();
    $this->post(route('integrations.frc.check'))->assertRedirect();
});
