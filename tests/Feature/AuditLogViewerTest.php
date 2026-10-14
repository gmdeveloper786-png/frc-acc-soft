<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Audit\AuditLogPresenter;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['name' => 'Audit Home Branch']);
    $this->secondBranch = Branch::factory()->create(['name' => 'Audit Second Branch']);
    $this->foreignBranch = Branch::factory()->create(['name' => 'Audit Foreign Branch']);
    $this->auditor = User::factory()->create(['name' => 'Audit Reader']);
    $this->auditor->givePermissionTo('audit_logs.view');
    $this->auditor->branches()->attach([$this->branch->id, $this->secondBranch->id]);
    $this->alice = User::factory()->create(['name' => 'Alice Actor']);
    $this->bob = User::factory()->create(['name' => 'Bob Actor']);
});

/** Writes an audit entry directly (the viewer itself never writes). */
function auditviewerEntry(string $event, array $attributes = []): AuditLog
{
    $log = (new AuditLog)->forceFill(['event' => $event, 'properties' => [], 'ip_address' => '10.1.2.3', ...$attributes]);
    $log->save();

    return $log;
}

/** @return list<int> Ids listed for the given filters, newest first. */
function auditviewerIds(object $test, array $filters = [], ?User $user = null): array
{
    return auditviewerIndex($test, $filters, $user)->viewData('logs')->pluck('id')->all();
}

function auditviewerIndex(object $test, array $filters = [], ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? $test->auditor)->get(route('audit-logs.index', $filters))->assertOk();
}

test('the audit log requires the audit_logs.view permission', function () {
    $entry = auditviewerEntry('settings.updated');
    $this->get(route('audit-logs.index'))->assertRedirect(route('login'));

    $outsider = User::factory()->create();
    $outsider->givePermissionTo(['journals.view', 'reports.view']);
    $outsider->branches()->attach($this->branch);
    $this->actingAs($outsider)->get(route('audit-logs.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('audit-logs.show', $entry))->assertForbidden();
    $this->actingAs($outsider)->get(route('dashboard'))->assertDontSee('Audit Log');

    $this->actingAs($this->auditor)->get(route('audit-logs.index'))->assertOk()->assertSee('settings.updated')->assertSee('Audit Log');
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $entry))->assertOk()->assertSee('settings.updated');
})->skip(fn () => ! Route::has('dashboard'), 'No dashboard route.');

test('each filter narrows the list correctly', function () {
    $posted = auditviewerEntry('journal.posted', ['actor_id' => $this->alice->id, 'branch_id' => $this->branch->id, 'auditable_type' => JournalEntry::class, 'auditable_id' => 7, 'properties' => ['voucher_number' => 'JV-2026-00007'], 'created_at' => '2026-03-01 09:00:00']);
    $denied = auditviewerEntry('purchase_orders.approval_denied', ['actor_id' => $this->bob->id, 'branch_id' => $this->branch->id, 'auditable_type' => PurchaseOrder::class, 'auditable_id' => 9, 'properties' => ['reason' => 'Needle-XYZ threshold'], 'created_at' => '2026-03-10 10:00:00']);
    $settings = auditviewerEntry('settings.updated', ['actor_id' => $this->alice->id, 'properties' => ['key' => 'organization.name'], 'created_at' => '2026-04-01 11:00:00']);
    $reversed = auditviewerEntry('journal.reversed', ['actor_id' => $this->bob->id, 'branch_id' => $this->secondBranch->id, 'auditable_type' => JournalEntry::class, 'auditable_id' => 7, 'properties' => ['reason' => 'Wrong account'], 'created_at' => '2026-04-05 12:00:00']);
    $blocked = auditviewerEntry('journal.cash_bank_credit_blocked', ['actor_id' => $this->bob->id, 'branch_id' => $this->secondBranch->id, 'created_at' => '2026-04-06 12:00:00']);
    $authority = auditviewerEntry('approval_authority.director_granted', ['actor_id' => $this->alice->id, 'auditable_type' => User::class, 'auditable_id' => $this->bob->id, 'created_at' => '2026-04-07 12:00:00']);

    expect(auditviewerIds($this))->toBe([$authority->id, $blocked->id, $reversed->id, $settings->id, $denied->id, $posted->id])
        ->and(auditviewerIds($this, ['from' => '2026-03-05', 'to' => '2026-04-01']))->toBe([$settings->id, $denied->id])
        ->and(auditviewerIds($this, ['to' => '2026-03-01']))->toBe([$posted->id])
        ->and(auditviewerIds($this, ['actor_id' => $this->alice->id]))->toBe([$authority->id, $settings->id, $posted->id])
        ->and(auditviewerIds($this, ['module' => 'journal']))->toBe([$blocked->id, $reversed->id, $posted->id])
        ->and(auditviewerIds($this, ['module' => 'purchase_orders']))->toBe([$denied->id])
        ->and(auditviewerIds($this, ['event' => 'journal.reversed']))->toBe([$reversed->id])
        ->and(auditviewerIds($this, ['record_type' => JournalEntry::class, 'record_id' => 7]))->toBe([$reversed->id, $posted->id])
        ->and(auditviewerIds($this, ['record_type' => PurchaseOrder::class]))->toBe([$denied->id])
        ->and(auditviewerIds($this, ['record_type' => JournalEntry::class, 'record_id' => 8]))->toBe([])
        ->and(auditviewerIds($this, ['branch_id' => $this->secondBranch->id]))->toBe([$blocked->id, $reversed->id])
        ->and(auditviewerIds($this, ['result' => 'refused']))->toBe([$blocked->id, $denied->id])
        ->and(auditviewerIds($this, ['result' => 'recorded']))->toBe([$authority->id, $reversed->id, $settings->id, $posted->id])
        ->and(auditviewerIds($this, ['search' => 'needle-xyz']))->toBe([$denied->id])
        ->and(auditviewerIds($this, ['search' => 'reversed']))->toBe([$reversed->id])
        ->and(auditviewerIds($this, ['search' => '100%']))->toBe([])
        ->and(auditviewerIds($this, ['quick' => 'director_authority']))->toBe([$authority->id])
        ->and(auditviewerIds($this, ['quick' => 'po_approval']))->toBe([$denied->id])
        ->and(auditviewerIds($this, ['quick' => 'posting']))->toBe([$posted->id])
        ->and(auditviewerIds($this, ['module' => 'journal', 'actor_id' => $this->bob->id, 'result' => 'recorded']))->toBe([$reversed->id]);

    $options = auditviewerIndex($this)->assertSee('Refused')->assertSee('Recorded')->assertSee('Journal Entry #7')->assertSee('Director authority')->viewData('options');
    expect($options['modules'])->toBe(['approval_authority', 'journal', 'purchase_orders', 'settings'])
        ->and($options['events'])->toContain('journal.cash_bank_credit_blocked')->not->toContain('payroll.approved')
        ->and($options['actors']->pluck('name')->all())->toBe(['Alice Actor', 'Bob Actor']);
    $this->actingAs($this->auditor)->get(route('audit-logs.index', ['result' => 'maybe']))->assertSessionHasErrors('result');
});

test('the list is paginated twenty five per page and keeps the filters', function () {
    foreach (range(1, 30) as $number) {
        auditviewerEntry('journal.posted', ['branch_id' => $this->branch->id, 'properties' => ['sequence' => $number]]);
    }
    auditviewerEntry('settings.updated');

    $first = auditviewerIndex($this, ['module' => 'journal']);
    expect($first->viewData('logs'))->toHaveCount(25)
        ->and($first->viewData('logs')->total())->toBe(30)
        ->and(auditviewerIndex($this, ['module' => 'journal', 'page' => 2])->viewData('logs'))->toHaveCount(5);
    $first->assertSee('module=journal', false)->assertSee('page=2', false);
});

test('entries of a branch outside the user scope are hidden and cannot be opened', function () {
    $own = auditviewerEntry('journal.posted', ['branch_id' => $this->branch->id]);
    $foreign = auditviewerEntry('journal.posted', ['branch_id' => $this->foreignBranch->id, 'properties' => ['voucher_number' => 'JV-FOREIGN-1']]);
    $global = auditviewerEntry('role.permissions_updated');

    expect(auditviewerIds($this))->toBe([$global->id, $own->id]);
    auditviewerIndex($this)->assertDontSee('Audit Foreign Branch');
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $foreign))->assertForbidden();
    $this->actingAs($this->auditor)->get(route('audit-logs.index', ['branch_id' => $this->foreignBranch->id]))->assertForbidden();
    expect(auditviewerIds($this, ['search' => 'JV-FOREIGN-1']))->toBe([]);
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $own))->assertOk()->assertSee('Audit Home Branch');
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $global))->assertOk()->assertSee('Not branch-specific');

    $everywhere = User::factory()->create();
    $everywhere->givePermissionTo(['audit_logs.view', 'branches.view_all']);
    expect(auditviewerIds($this, [], $everywhere))->toBe([$global->id, $foreign->id, $own->id]);
    $this->actingAs($everywhere)->get(route('audit-logs.show', $foreign))->assertOk()->assertSee('JV-FOREIGN-1');
});

test('secrets and personal identifiers are never displayed', function () {
    $entry = auditviewerEntry('user.access_updated', [
        'actor_id' => $this->alice->id,
        'user_agent' => str_repeat('Mozilla/5.0 LongAgent ', 12).'TAIL-MARKER',
        'properties' => [
            'old_password' => 'PlainPass-111', 'api_token' => 'tok-222-secret', 'client_secret' => 'sec-333', 'APP_KEY' => 'base64:key-444',
            'remember_me' => 'rem-555', 'Authorization' => 'Bearer 666', 'cnic' => '35202-7777777-7', 'account_number' => '0088-9999',
            'bank_details' => ['iban' => 'PK36SCBL0000001123456702', 'title' => 'Holder-AAA'],
            'changed' => ['name' => ['from' => 'Old Name', 'to' => 'New Name'], 'reset_token' => 'nested-tok-888'],
            'roles' => ['Accountant', 'Auditor'], 'is_active' => true, 'note' => '<script>alert(1)</script> Visible note',
        ],
    ]);

    $response = $this->actingAs($this->auditor)->get(route('audit-logs.show', $entry))->assertOk();
    foreach (['PlainPass-111', 'tok-222-secret', 'sec-333', 'key-444', 'rem-555', 'Bearer 666', '35202-7777777-7', '0088-9999', 'PK36SCBL0000001123456702', 'Holder-AAA', 'nested-tok-888', 'TAIL-MARKER'] as $secret) {
        $response->assertDontSee($secret);
    }
    $response->assertSee(AuditLogPresenter::HIDDEN)->assertSee('Changed › Name › To')->assertSee('New Name')->assertSee('Accountant, Auditor')
        ->assertSee('Visible note')->assertDontSee('<script>alert(1)</script>', false)->assertSee('Alice Actor')->assertSee('10.1.2.3')->assertSee('Mozilla/5.0 LongAgent');
    expect(collect(app(AuditLogPresenter::class)->properties($entry, $this->auditor))->where('masked', true)->count())->toBe(10);
});

test('payroll and employee amounts are masked without payroll.view_sensitive and shown with it', function () {
    $run = auditviewerEntry('payroll.approved', ['branch_id' => $this->branch->id, 'auditable_type' => PayrollRun::class, 'auditable_id' => 3, 'properties' => ['gross_amount' => '250000.0000', 'total_deductions' => '18999.5000', 'net_amount' => '231000.5000', 'line_count' => 4]]);
    $employee = auditviewerEntry('employees.updated', ['branch_id' => $this->branch->id, 'properties' => ['employee_code' => 'EMP-T0001', 'changed' => ['basic_salary' => ['from' => 90000, 'to' => 95500], 'designation' => ['from' => 'Therapist', 'to' => 'Senior Therapist']], 'other' => '777.2500']]);
    $journal = auditviewerEntry('journal.posted', ['branch_id' => $this->branch->id, 'properties' => ['total_debit' => '4321.0000']]);

    $this->actingAs($this->auditor)->get(route('audit-logs.show', $run))->assertOk()
        ->assertDontSee('250000.0000')->assertDontSee('18999.5000')->assertDontSee('231000.5000')
        ->assertSee(AuditLogPresenter::RESTRICTED)->assertSee('Line Count')->assertSee('permission to view sensitive payroll data');
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $employee))->assertOk()
        ->assertDontSee('95500')->assertDontSee('90000')->assertDontSee('777.2500')->assertSee('EMP-T0001')->assertSee('Senior Therapist');
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $journal))->assertOk()->assertSee('4321.0000')->assertDontSee(AuditLogPresenter::RESTRICTED);
    // Masked amounts cannot be found by searching for them either.
    expect(auditviewerIds($this, ['search' => '231000.5000']))->toBe([])
        ->and(auditviewerIds($this, ['search' => '4321.0000']))->toBe([$journal->id])
        ->and(auditviewerIds($this, ['search' => 'payroll.appr']))->toBe([$run->id]);

    $payrollReader = User::factory()->create();
    $payrollReader->givePermissionTo(['audit_logs.view', 'payroll.view_sensitive']);
    $payrollReader->branches()->attach($this->branch);
    $this->actingAs($payrollReader)->get(route('audit-logs.show', $run))->assertOk()
        ->assertSee('250000.0000')->assertSee('231000.5000')->assertDontSee(AuditLogPresenter::RESTRICTED);
    $this->actingAs($payrollReader)->get(route('audit-logs.show', $employee))->assertOk()->assertSee('95500');
    expect(auditviewerIds($this, ['search' => '231000.5000'], $payrollReader))->toBe([$run->id]);
});

test('audit records have no write routes and the model refuses modification', function () {
    $entry = auditviewerEntry('journal.posted', ['branch_id' => $this->branch->id]);
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'audit-log'));

    expect($routes)->toHaveCount(2)
        ->and($routes->flatMap(fn ($route): array => $route->methods())->unique()->sort()->values()->all())->toBe(['GET', 'HEAD']);
    $this->actingAs($this->auditor);
    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        $this->{$verb}('/audit-logs/'.$entry->id)->assertStatus(405);
    }
    $this->post('/audit-logs')->assertStatus(405);

    expect(fn () => $entry->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->find($entry->id)->event)->toBe('journal.posted');
});

test('browsing the audit log writes nothing', function () {
    $entry = auditviewerEntry('journal.posted', ['branch_id' => $this->branch->id, 'properties' => ['voucher_number' => 'JV-1']]);
    auditviewerEntry('settings.updated');
    $count = AuditLog::query()->count();
    $latest = AuditLog::query()->max('updated_at');

    auditviewerIndex($this);
    auditviewerIndex($this, ['module' => 'journal', 'search' => 'JV', 'result' => 'recorded', 'quick' => 'posting']);
    $this->actingAs($this->auditor)->get(route('audit-logs.show', $entry))->assertOk();
    $this->actingAs($this->auditor)->get(route('audit-logs.show', 999999))->assertNotFound();

    expect(AuditLog::query()->count())->toBe($count)
        ->and(AuditLog::query()->max('updated_at'))->toBe($latest);
});
