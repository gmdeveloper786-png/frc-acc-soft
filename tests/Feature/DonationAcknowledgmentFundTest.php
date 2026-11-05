<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\User;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function (): void {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
        DonationCategorySeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Other Branch']);

    $this->accountant = donationAckTestUser(SystemRole::Accountant, $this->branch);
    $this->otherAccountant = donationAckTestUser(SystemRole::Accountant, $this->otherBranch);

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

    $this->bankLedger = Account::factory()->create(['code' => '111102', 'name' => 'MCB Bank Account']);
    $this->generalIncomeLedger = Account::factory()->create(['code' => '43101', 'name' => 'General Donations', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->zakatIncomeLedger = Account::factory()->create(['code' => '43102', 'name' => 'Zakat Contributions', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->projectIncomeLedger = Account::factory()->create(['code' => '43105', 'name' => 'Project Donations', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->receiptIncomeLedger = Account::factory()->create(['code' => '411101', 'name' => 'Service Fee Income', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableLedger->id]);

    $this->bank = FinancialAccount::factory()->create([
        'type' => 'bank',
        'branch_id' => $this->branch,
        'account_id' => $this->bankLedger,
        'is_active' => true,
    ]);

    $this->dnrParent = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR',
        'name' => 'Donor-Restricted Fund',
        'is_active' => true,
    ]);

    $this->dnrChild = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-UAT1',
        'name' => 'DEMO UAT Rehabilitation Project',
        'parent_id' => $this->dnrParent->id,
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

    $this->projectCategory = DonationCategory::query()->where('slug', 'new-frc-project-donation')->firstOrFail();
    $this->projectCategory->update(['income_account_id' => $this->projectIncomeLedger->id]);

    $this->zakatCategory = DonationCategory::query()->where('slug', 'zakat')->firstOrFail();
    $this->zakatCategory->update(['fund_id' => $this->zktFund->id, 'income_account_id' => $this->zakatIncomeLedger->id]);

    $this->generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $this->generalCategory->update(['fund_id' => $this->genFund->id, 'income_account_id' => $this->generalIncomeLedger->id]);

    $this->service = app(OperationalTransactionService::class);
});

function donationAckTestUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

test('Project Donation acknowledgment displays Project / Restricted Fund with DNR child fund identification (simulating Donation #43)', function (): void {
    $draft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '3000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrChild->id,
        'party_name' => 'DEMO UAT Project Donor',
        'reference_number' => 'UAT-DON-PROJECT-POST-001',
        'narration' => 'Project donation test acknowledgment',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    $response = $this->actingAs($this->accountant)->get(route('transactions.acknowledgment', $posted));
    $response->assertOk();
    $response->assertSee('Donation Acknowledgment');
    $response->assertSee('Project / Restricted Fund');
    $response->assertSee('DNR-UAT1 — DEMO UAT Rehabilitation Project');
    $response->assertSee('DEMO UAT Project Donor');
    $response->assertSee('New FRC Project Donation');
    $response->assertSee('3,000.00');
    $response->assertSee($posted->journalEntry->voucher_number);
});

test('General Donation acknowledgment displays Fund with GEN fund identification', function (): void {
    $draft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '5000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->genFund->id,
        'party_name' => 'DEMO UAT General Donor',
        'reference_number' => 'UAT-DON-GEN-001',
        'narration' => 'General donation acknowledgment test',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    $response = $this->actingAs($this->accountant)->get(route('transactions.acknowledgment', $posted));
    $response->assertOk();
    $response->assertSee('Donation Acknowledgment');
    $response->assertSee('<dt class="col-5">Fund</dt>', false);
    $response->assertSee('GEN — General Operating Fund');
    $response->assertSee('5,000.00');
    $response->assertDontSee('Project / Restricted Fund');
});

test('Zakat Donation acknowledgment displays Fund with ZKT fund identification', function (): void {
    $draft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '2000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->zakatCategory->id,
        'fund_id' => $this->zktFund->id,
        'party_name' => 'DEMO UAT Zakat Donor',
        'reference_number' => 'UAT-DON-ZKT-001',
        'narration' => 'Zakat donation acknowledgment test',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    $response = $this->actingAs($this->accountant)->get(route('transactions.acknowledgment', $posted));
    $response->assertOk();
    $response->assertSee('Donation Acknowledgment');
    $response->assertSee('<dt class="col-5">Fund</dt>', false);
    $response->assertSee('ZKT — Zakat / Charity Fund');
    $response->assertSee('2,000.00');
    $response->assertDontSee('Project / Restricted Fund');
});

test('acknowledgment handles missing fund gracefully without error or broken layout', function (): void {
    $draft = $this->service->create([
        'type' => 'bank_receipt',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '1500.0000',
        'financial_account_id' => $this->bank->id,
        'contra_account_id' => $this->receiptIncomeLedger->id,
        'fund_id' => null,
        'party_name' => 'Walk-in Client',
        'reference_number' => 'RCP-001',
        'narration' => 'Fee receipt with no fund allocated',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    $response = $this->actingAs($this->accountant)->get(route('transactions.acknowledgment', $posted));
    $response->assertOk();
    $response->assertSee('Bank Receipt');
    $response->assertSee('1,500.00');
    $response->assertDontSee('Project / Restricted Fund');
    $response->assertDontSee('<dt class="col-5">Fund</dt>', false);
    $response->assertDontSee('<dt class="col-5">Fund Allocation</dt>', false);
});

test('acknowledgment print layout enforces portrait orientation and suppresses duplicate workspace header', function (): void {
    $draft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '3000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrChild->id,
        'party_name' => 'Print Layout Donor',
        'narration' => 'Print layout test',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    $response = $this->actingAs($this->accountant)->get(route('transactions.acknowledgment', $posted));
    $response->assertOk();

    // 1. Workspace header is suppressed in print media via d-print-none
    $response->assertSee('page-header d-print-none', false);

    // 2. CSS includes portrait page sizing and page-break suppression for single-page printing
    $response->assertSee('@page { size: portrait; margin: 10mm; }', false);
    $response->assertSee('page-break-inside: avoid;', false);
    $response->assertSee('receipt-card', false);
});

test('acknowledgment enforces branch access controls and view authorization', function (): void {
    $draft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '3000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrChild->id,
        'narration' => 'Security test',
    ], $this->accountant);

    $posted = $this->service->post($draft, $this->accountant);

    // Another branch user is forbidden
    $this->actingAs($this->otherAccountant)
        ->get(route('transactions.acknowledgment', $posted))
        ->assertForbidden();

    // Unposted draft cannot have an acknowledgment receipt
    $unpostedDraft = $this->service->create([
        'type' => 'donation',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '1000.0000',
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->projectCategory->id,
        'fund_id' => $this->dnrChild->id,
        'narration' => 'Unposted draft test',
    ], $this->accountant);

    $this->actingAs($this->accountant)
        ->get(route('transactions.acknowledgment', $unpostedDraft))
        ->assertNotFound();
});
