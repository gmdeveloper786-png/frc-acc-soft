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

beforeEach(function () {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
        DonationCategorySeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);

    $this->admin = projectDonationTestUser(SystemRole::Administrator, $this->branch);
    $this->accountant = projectDonationTestUser(SystemRole::Accountant, $this->branch);

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

    $this->bankLedger = Account::factory()->create(['code' => '111102', 'name' => 'Bank Account']);
    $this->incomeLedger = Account::factory()->create(['code' => '43105', 'name' => 'Project Donations', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableLedger->id]);

    $this->bank = FinancialAccount::factory()->create([
        'type' => 'bank',
        'branch_id' => $this->branch,
        'account_id' => $this->bankLedger,
        'is_active' => true,
    ]);

    $this->projectCategory = DonationCategory::query()->where('slug', 'new-frc-project-donation')->firstOrFail();
    $this->projectCategory->update(['income_account_id' => $this->incomeLedger->id]);

    $this->dnrParent = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR',
        'name' => 'Donor Restricted Funds',
        'parent_id' => null,
        'is_active' => true,
    ]);

    $this->activeChildFund = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-UAT1',
        'name' => 'DEMO UAT Rehabilitation Project',
        'parent_id' => $this->dnrParent->id,
        'is_active' => true,
    ]);

    $this->inactiveChildFund = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-OLD',
        'name' => 'Closed Project',
        'parent_id' => $this->dnrParent->id,
        'is_active' => false,
    ]);

    $this->genFund = Fund::factory()->create([
        'code' => 'GEN',
        'name' => 'General Operating Fund',
        'type' => Fund::TYPE_UNRESTRICTED,
        'category' => 'general',
        'is_active' => true,
    ]);

    $this->service = app(OperationalTransactionService::class);
});

function projectDonationTestUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

test('legacy project donation draft with parent DNR cannot be posted, canPost returns false, and direct POST is blocked', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrParent->id,
        'amount' => 3000,
        'reference_number' => 'UAT-DON-PROJECT-001',
        'narration' => 'Legacy draft simulating Donation #31',
        'created_by' => $this->accountant->id,
    ]);

    expect($this->service->canPost($draft, $this->accountant))->toBeFalse();
    expect($this->service->postingBlockedReason($draft))
        ->toBe('New FRC Project Donations must be allocated to an active project child fund under DNR before posting. Generic parent DNR cannot be used.');

    // Direct HTTP POST to post route is blocked
    $this->actingAs($this->accountant)
        ->post(route('transactions.post', $draft))
        ->assertSessionHasErrors(['fund_id' => 'New FRC Project Donations must be allocated to an active project child fund under DNR before posting. Generic parent DNR cannot be used.']);

    $draft->refresh();
    expect($draft->status)->toBe(OperationalTransactionStatus::Draft);
    expect($draft->journal_entry_id)->toBeNull();
});

test('legacy project donation draft with parent DNR displays ineligibility warning and hides Post button on detail screen', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrParent->id,
        'amount' => 3000,
        'reference_number' => 'UAT-DON-PROJECT-001',
        'created_by' => $this->accountant->id,
    ]);

    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $draft));

    $response->assertOk();
    $response->assertSee('Fund Policy Notice:');
    $response->assertSee('Generic parent DNR cannot be used.');
    $response->assertSee('Ineligible for posting');
    $response->assertSee('Posting Ineligible:');
    $response->assertDontSee('title="Post to Journal Engine"', false);
    $response->assertSee('Cancel Draft');
});

test('project donation draft with no fund cannot be posted', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => null,
        'amount' => 1000,
        'created_by' => $this->accountant->id,
    ]);

    expect($this->service->canPost($draft, $this->accountant))->toBeFalse();
    expect(fn () => $this->service->post($draft, $this->accountant))
        ->toThrow(ValidationException::class, 'New FRC Project Donations must be allocated to an active project child fund under DNR before posting.');
});

test('project donation draft with inactive child fund cannot be posted', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->inactiveChildFund->id,
        'amount' => 1000,
        'created_by' => $this->accountant->id,
    ]);

    expect($this->service->canPost($draft, $this->accountant))->toBeFalse();
    expect(fn () => $this->service->post($draft, $this->accountant))
        ->toThrow(ValidationException::class, 'The selected project fund is not active.');
});

test('project donation draft with unrelated fund (such as GEN) cannot be posted', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->genFund->id,
        'amount' => 1000,
        'created_by' => $this->accountant->id,
    ]);

    expect($this->service->canPost($draft, $this->accountant))->toBeFalse();
    expect(fn () => $this->service->post($draft, $this->accountant))
        ->toThrow(ValidationException::class, 'New FRC Project Donations must be allocated to an active project child fund under DNR before posting. Generic parent DNR cannot be used.');
});

test('project donation draft with valid active DNR child fund (simulating Donation #32) can be posted and creates balanced journal', function () {
    $draft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->activeChildFund->id,
        'amount' => 3000,
        'reference_number' => 'UAT-DON-PROJECT-002',
        'narration' => 'Valid project donation draft',
        'created_by' => $this->accountant->id,
    ]);

    expect($this->service->canPost($draft, $this->accountant))->toBeTrue();
    expect($this->service->postingBlockedReason($draft))->toBeNull();

    // UI shows Post button and no ineligibility notice
    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $draft));
    $response->assertOk();
    $response->assertDontSee('Fund Policy Notice:');
    $response->assertDontSee('Ineligible for posting');
    $response->assertSee('Post to Journal Engine');

    // Posting succeeds
    $posted = $this->service->post($draft, $this->accountant);
    expect($posted->status)->toBe(OperationalTransactionStatus::Posted);
    expect($posted->journal_entry_id)->not->toBeNull();
    expect((float) $posted->journalEntry->total_debit)->toBe(3000.0);
    expect((float) $posted->journalEntry->total_credit)->toBe(3000.0);
});
