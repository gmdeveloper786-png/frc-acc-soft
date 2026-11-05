<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
        DonationCategorySeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);

    $this->accountant = zakatPolicyTestUser(SystemRole::Accountant, $this->branch);

    $this->year = FiscalYear::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);

    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->cashLedger = Account::factory()->create(['code' => '111101', 'name' => 'Cash Account']);
    $this->zakatIncomeLedger = Account::factory()->create(['code' => '43102', 'name' => 'Zakat Contributions', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->generalIncomeLedger = Account::factory()->create(['code' => '43101', 'name' => 'General Donations', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableLedger->id]);

    $this->cashAccount = FinancialAccount::factory()->create([
        'type' => 'cash',
        'branch_id' => $this->branch,
        'account_id' => $this->cashLedger,
        'is_active' => true,
    ]);

    $this->zktFund = Fund::factory()->restricted('zakat')->create([
        'code' => 'ZKT',
        'name' => 'Zakat / Charity Fund',
        'is_active' => true,
    ]);

    $this->genFund = Fund::factory()->create([
        'code' => 'GEN',
        'name' => 'General Operating Fund',
        'type' => Fund::TYPE_UNRESTRICTED,
        'category' => 'general',
        'is_active' => true,
    ]);

    $this->dnrParent = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR',
        'name' => 'Donor-Restricted Fund',
        'is_active' => true,
    ]);

    $this->grtFund = Fund::factory()->restricted('grant')->create([
        'code' => 'GRT-001',
        'name' => 'Grant Fund',
        'is_active' => true,
    ]);

    $this->capFund = Fund::factory()->restricted('capital')->create([
        'code' => 'CAP-001',
        'name' => 'Capital Fund',
        'is_active' => true,
    ]);

    $this->zakatCategory = DonationCategory::query()->where('slug', 'zakat')->firstOrFail();
    $this->zakatCategory->update(['fund_id' => $this->zktFund->id, 'income_account_id' => $this->zakatIncomeLedger->id]);

    $this->generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $this->generalCategory->update(['fund_id' => $this->genFund->id, 'income_account_id' => $this->generalIncomeLedger->id]);

    $this->service = app(OperationalTransactionService::class);
});

function zakatPolicyTestUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

test('HTTP store rejects Zakat donation when fund_id is empty or null', function (): void {
    $response = $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '2000.0000',
        'financial_account_id' => $this->cashAccount->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => '',
        'party_name' => 'Zakat Donor',
        'narration' => 'Zakat donation with missing fund',
    ]);

    $response->assertSessionHasErrors(['fund_id' => 'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund.']);
});

test('HTTP store rejects manipulated Zakat donation with non-ZKT funds (GEN, DNR, GRT, CAP)', function (): void {
    foreach ([$this->genFund, $this->dnrParent, $this->grtFund, $this->capFund] as $invalidFund) {
        $response = $this->actingAs($this->accountant)->post(route('transactions.store'), [
            'type' => 'donation',
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-01-15',
            'amount' => '2000.0000',
            'financial_account_id' => $this->cashAccount->id,
            'donation_category_id' => $this->zakatCategory->id,
            'fund_id' => $invalidFund->id,
            'party_name' => 'Zakat Donor',
            'narration' => "Zakat donation with {$invalidFund->code}",
        ]);

        $response->assertSessionHasErrors('fund_id');
    }
});

test('HTTP store succeeds with explicit ZKT fund and saves explicit fund_id', function (): void {
    $response = $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '2000.0000',
        'financial_account_id' => $this->cashAccount->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => $this->zktFund->id,
        'party_name' => 'Zakat Donor Valid',
        'narration' => 'Zakat donation with explicit ZKT fund',
    ]);

    $response->assertSessionHasNoErrors();
    $donation = OperationalTransaction::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('transactions.show', $donation));

    expect($donation->fund_id)->toBe($this->zktFund->id)
        ->and($donation->status)->toBe(OperationalTransactionStatus::Draft);
});

test('legacy Zakat Draft with null or non-ZKT fund is blocked from posting on UI and backend', function (): void {
    // 1. Draft with null fund
    $draftNullFund = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->cashAccount->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => null,
        'amount' => 2000,
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->accountant->id,
        'narration' => 'Legacy Zakat Draft with null fund',
    ]);

    expect($this->service->canPost($draftNullFund, $this->accountant))->toBeFalse();
    expect($this->service->postingBlockedReason($draftNullFund))
        ->toBe('Zakat donations must be allocated to the ZKT (Zakat / Charity) fund before posting.');

    // UI verifies ineligibility
    $res = $this->actingAs($this->accountant)->get(route('transactions.show', $draftNullFund));
    $res->assertOk();
    $res->assertSee('Fund Policy Notice:');
    $res->assertSee('Ineligible for posting');
    $res->assertDontSee('Post to Journal Engine');

    // Direct posting attempt throws
    expect(fn () => $this->service->post($draftNullFund, $this->accountant))
        ->toThrow(ValidationException::class, 'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund before posting.');

    // 2. Draft with non-ZKT fund (e.g. GEN)
    $draftGenFund = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->cashAccount->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => $this->genFund->id,
        'amount' => 2000,
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->accountant->id,
        'narration' => 'Legacy Zakat Draft with GEN fund',
    ]);

    expect($this->service->canPost($draftGenFund, $this->accountant))->toBeFalse();
    expect($this->service->postingBlockedReason($draftGenFund))
        ->toBe('Zakat donations must be allocated to the ZKT (Zakat / Charity) fund before posting.');

    expect(fn () => $this->service->post($draftGenFund, $this->accountant))
        ->toThrow(ValidationException::class, 'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund before posting.');
});

test('valid Zakat Draft with explicit ZKT fund can be posted and generates balanced journal', function (): void {
    $validDraft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->cashAccount->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => $this->zktFund->id,
        'amount' => 2000,
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->accountant->id,
        'narration' => 'Valid Zakat Draft (simulating Donation #30)',
    ]);

    expect($this->service->canPost($validDraft, $this->accountant))->toBeTrue();
    expect($this->service->postingBlockedReason($validDraft))->toBeNull();

    // UI shows Post button
    $res = $this->actingAs($this->accountant)->get(route('transactions.show', $validDraft));
    $res->assertOk();
    $res->assertDontSee('Fund Policy Notice:');
    $res->assertDontSee('Ineligible for posting');
    $res->assertSee('Post to Journal Engine');

    // Post to journal
    $posted = $this->service->post($validDraft, $this->accountant);
    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($posted->journal_entry_id)->not->toBeNull()
        ->and((float) $posted->journalEntry->total_debit)->toBe(2000.0)
        ->and((float) $posted->journalEntry->total_credit)->toBe(2000.0)
        ->and($posted->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([$this->zktFund->id]);
});

test('create view displays unambiguous explicit ZKT fund option without category default label for Zakat', function (): void {
    $res = $this->actingAs($this->accountant)
        ->withSession(['_old_input' => ['donation_category_id' => $this->zakatCategory->id]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();

    // Does NOT say "Use category default" for Zakat
    $res->assertDontSee('Use category default (ZKT');
    $res->assertSee('Select ZKT — Zakat / Charity Fund');
    $res->assertSee('Mandatory: Every Zakat donation must use the ZKT — Zakat / Charity Fund.');

    // General Donation preserves category default
    $resGeneral = $this->actingAs($this->accountant)
        ->withSession(['_old_input' => ['donation_category_id' => $this->generalCategory->id]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();

    $resGeneral->assertSee('Use category default (GEN — General Operating Fund)');
});
