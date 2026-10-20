<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Services\TrialBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->maker = openingUser(SystemRole::Accountant, $this->branch);
    $this->poster = openingUser(SystemRole::Accountant, $this->branch);
    $this->approver = openingUser(SystemRole::Approver, $this->branch);
    $this->admin = openingUser(SystemRole::Administrator, $this->branch);
    $this->director = openingUser(SystemRole::Director, $this->branch);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->asset = Account::factory()->create(['code' => '11102']);
    $this->equity = Account::factory()->create(['code' => '31101', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->service = app(OpeningBalancePostingService::class);
});

function openingUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** Save a balanced draft as $actor (signed in, so the audit records who saved it). */
function openingDraft(object $test, User $actor, string $amount = '890000.00'): OpeningBalanceBatch
{
    $test->actingAs($actor);

    return app(OpeningBalanceService::class)->saveDraft([
        'fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'opening_date' => '2026-01-01',
        'lines' => [['account_id' => $test->asset->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $test->equity->id, 'debit' => '0', 'credit' => $amount]],
    ], $actor);
}

function approvedOpening(object $test, ?User $maker = null, ?User $approver = null): OpeningBalanceBatch
{
    $batch = openingDraft($test, $maker ?? $test->maker);
    $test->actingAs($approver ?? $test->approver);

    return $test->service->approve($batch, $approver ?? $test->approver)->fresh();
}

function expectOpeningUnposted(OpeningBalanceBatch $batch): void
{
    expect($batch->fresh()->status)->toBe('approved')->and($batch->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('source_type', 'opening_balance')->count())->toBe(0);
}

test('no one who created edited or imported a batch can approve it', function () {
    $own = openingDraft($this, $this->admin);
    expect($this->service->canApprove($own, $this->admin))->toBeFalse()
        ->and(fn () => $this->service->approve($own, $this->admin))->toThrow(ValidationException::class);

    // Created by the maker, edited by the Administrator, saved again by the maker: the Administrator stays a preparer.
    $batch = openingDraft($this, $this->maker);
    openingDraft($this, $this->admin);
    openingDraft($this, $this->maker);
    expect($batch->fresh()->updated_by)->toBe($this->maker->id)
        ->and($this->service->canApprove($batch->fresh(), $this->admin))->toBeFalse()
        ->and(fn () => $this->service->approve($batch->fresh(), $this->admin))->toThrow(ValidationException::class)
        ->and($this->service->canApprove($batch->fresh(), $this->approver))->toBeTrue();
});

test('the maker cannot post their own approved batch through the service the screen or a forged request', function () {
    $batch = approvedOpening($this);

    expect($this->service->canPost($batch, $this->maker))->toBeFalse()
        ->and(fn () => $this->service->post($batch, $this->maker))->toThrow(ValidationException::class);
    $this->actingAs($this->maker)->get(route('opening-balances.show', $batch))->assertOk()
        ->assertDontSee('Post through Journal Engine')->assertSee('Posting needs a third user');
    $this->post(route('opening-balances.post', $batch))->assertSessionHasErrors('approval');
    expectOpeningUnposted($batch);
});

test('the approver cannot post what they approved and no privileged role bypasses the rule', function () {
    $byAdmin = approvedOpening($this, $this->maker, $this->admin);
    $this->actingAs($this->admin)->get(route('opening-balances.show', $byAdmin))->assertOk()->assertDontSee('Post through Journal Engine');
    $this->post(route('opening-balances.post', $byAdmin))->assertSessionHasErrors('approval');
    expectOpeningUnposted($byAdmin);
    DB::table('opening_balance_batches')->delete();

    // A Director who approved cannot post either, even if given the posting permission directly.
    $byDirector = approvedOpening($this, $this->maker, $this->director);
    $this->actingAs($this->director)->post(route('opening-balances.post', $byDirector))->assertForbidden();
    $this->director->givePermissionTo('opening_balances.post');
    expect(fn () => $this->service->post($byDirector->fresh(), $this->director))->toThrow(ValidationException::class);
    expectOpeningUnposted($byDirector);
});

test('an independent third user posts exactly one balanced journal with the right identities', function () {
    $batch = approvedOpening($this);
    $this->actingAs($this->poster)->get(route('opening-balances.show', $batch))->assertOk()->assertSee('Post through Journal Engine');
    $this->post(route('opening-balances.post', $batch))->assertRedirect()->assertSessionHasNoErrors();

    $batch->refresh();
    $journal = JournalEntry::query()->where('source_type', 'opening_balance')->sole();
    $trial = app(TrialBalanceService::class)->report($this->year->id, '2026-01-31', [$this->branch->id]);
    expect($batch->status)->toBe('posted')->and($batch->journal_entry_id)->toBe($journal->id)
        ->and([$batch->created_by, $batch->approved_by, $batch->posted_by])->toBe([$this->maker->id, $this->approver->id, $this->poster->id])
        ->and($journal->voucher_number)->toStartWith('JV-')->and($journal->source_key)->toBe('opening_balance:'.$batch->id)
        ->and([$journal->created_by, $journal->approved_by, $journal->posted_by])->toBe([$this->maker->id, $this->approver->id, $this->poster->id])
        ->and($journal->total_debit)->toBe('890000.0000')->and($journal->total_credit)->toBe('890000.0000')
        ->and($trial['balanced'])->toBeTrue()
        ->and(AuditLog::query()->where('event', 'opening_balance.posted')->where('auditable_id', $batch->id)->value('properties'))
        ->toMatchArray(['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, 'approved_by' => $this->approver->id]);

    // Posting again, by anyone, is refused and never creates a second journal.
    expect(fn () => $this->service->post($batch, $this->admin))->toThrow(ValidationException::class);
    $this->actingAs($this->admin)->post(route('opening-balances.post', $batch))->assertSessionHasErrors('status');
    expect(JournalEntry::query()->where('source_type', 'opening_balance')->count())->toBe(1);

    // The posted batch stays readable, with its journal and no further action.
    $this->actingAs($this->poster)->get(route('opening-balances.show', $batch))->assertOk()->assertSee('View Journal')->assertDontSee('Post through Journal Engine');
});

test('users without permission or branch access cannot approve or post', function () {
    $cashier = openingUser(SystemRole::Cashier, $this->branch);
    $outsideApprover = openingUser(SystemRole::Approver, $this->otherBranch);
    $outsidePoster = openingUser(SystemRole::Accountant, $this->otherBranch);
    $draft = openingDraft($this, $this->maker);

    $this->actingAs($cashier)->post(route('opening-balances.approve', $draft))->assertForbidden();
    expect(fn () => $this->service->approve($draft, $outsideApprover))->toThrow(HttpException::class);
    $batch = $this->service->approve($draft, $this->approver)->fresh();

    $this->actingAs($cashier)->post(route('opening-balances.post', $batch))->assertForbidden();
    $this->actingAs($this->approver)->post(route('opening-balances.post', $batch))->assertForbidden();
    $this->actingAs($outsidePoster)->post(route('opening-balances.post', $batch))->assertForbidden();
    expect($this->service->canPost($batch, $outsidePoster))->toBeFalse();
    expectOpeningUnposted($batch);
});

test('posting rechecks that the approval still stands', function (Closure $invalidate) {
    $batch = approvedOpening($this);
    $invalidate($this, $batch);

    expect($this->service->canPost($batch->fresh(), $this->poster))->toBeFalse()
        ->and(fn () => $this->service->post($batch->fresh(), $this->poster))->toThrow(ValidationException::class, 'no longer valid');
    expectOpeningUnposted($batch);
})->with([
    'approver lost the approval role' => [fn ($test) => $test->approver->removeRole(SystemRole::Approver->value)],
    'approver deactivated' => [fn ($test) => $test->approver->update(['is_active' => false])],
    'approver lost the branch' => [fn ($test) => $test->approver->branches()->detach($test->branch)],
    'no recorded approver' => [fn ($test, $batch) => DB::table('opening_balance_batches')->where('id', $batch->id)->update(['approved_by' => null])],
    'approver is the creator' => [fn ($test, $batch) => DB::table('opening_balance_batches')->where('id', $batch->id)->update(['approved_by' => $test->maker->id])],
    'batch saved again after approval' => [fn ($test, $batch) => app(AuditLogger::class)->log('opening_balance.draft_saved', $batch, [], $test->maker)],
]);

test('closed periods and invalid accounts still block an otherwise eligible poster', function () {
    $batch = approvedOpening($this);

    $this->period->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => $this->service->post($batch->fresh(), $this->poster))->toThrow(ValidationException::class);
    $this->period->update(['status' => AccountingPeriodStatus::Open]);

    $this->asset->update(['is_active' => false]);
    expect(fn () => $this->service->post($batch->fresh(), $this->poster))->toThrow(ValidationException::class);
    expectOpeningUnposted($batch);
});
