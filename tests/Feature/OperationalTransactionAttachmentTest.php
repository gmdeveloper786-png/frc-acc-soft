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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');

    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
        DonationCategorySeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Other Branch']);

    $this->admin = attachmentTestUser(SystemRole::Administrator, $this->branch);
    $this->accountant = attachmentTestUser(SystemRole::Accountant, $this->branch);
    $this->otherAccountant = attachmentTestUser(SystemRole::Accountant, $this->otherBranch);
    $this->auditor = attachmentTestUser(SystemRole::Auditor, $this->branch);
    $this->unauthorizedUser = User::factory()->create(['is_active' => true]);
    $this->unauthorizedUser->branches()->attach($this->branch);

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
    $this->incomeLedger = Account::factory()->create(['code' => '43101', 'name' => 'General Donations', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableLedger->id]);

    $this->bank = FinancialAccount::factory()->create([
        'type' => 'bank',
        'branch_id' => $this->branch,
        'account_id' => $this->bankLedger,
        'is_active' => true,
    ]);

    $this->generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $this->generalCategory->update(['income_account_id' => $this->incomeLedger->id]);

    $this->dnrParent = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR',
        'name' => 'Donor Restricted Funds',
        'is_active' => true,
    ]);

    $this->restrictedProjectFund = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-TEST1',
        'name' => 'Restricted Test Project',
        'parent_id' => $this->dnrParent->id,
        'is_active' => true,
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

function attachmentTestUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

test('general donation allocated to restricted fund requires supporting document attachment', function () {
    $this->actingAs($this->accountant)
        ->post(route('transactions.store'), [
            'type' => OperationalTransactionType::Donation->value,
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-01-15',
            'amount' => '500.00',
            'financial_account_id' => $this->bank->id,
            'donation_category_id' => $this->generalCategory->id,
            'fund_id' => $this->restrictedProjectFund->id,
            'reference_number' => 'REF-FAIL-001',
            'narration' => 'Test missing attachment',
        ])
        ->assertSessionHasErrors(['attachment' => 'Allocating a General Donation to a donor-restricted fund requires an attached donor-written restriction document.']);
});

test('general donation allocated to GEN fund allows optional attachment', function () {
    $response = $this->actingAs($this->accountant)
        ->post(route('transactions.store'), [
            'type' => OperationalTransactionType::Donation->value,
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-01-15',
            'amount' => '500.00',
            'financial_account_id' => $this->bank->id,
            'donation_category_id' => $this->generalCategory->id,
            'fund_id' => $this->genFund->id,
            'reference_number' => 'REF-GEN-001',
            'narration' => 'General donation without attachment',
        ]);

    $response->assertSessionHasNoErrors();
    $donation = OperationalTransaction::query()->where('reference_number', 'REF-GEN-001')->firstOrFail();
    expect($donation->attachment_path)->toBeNull();
    expect($donation->attachment_original_name)->toBeNull();
});

test('general donation to restricted fund with uploaded attachment stores private file and preserves original filename', function () {
    $file = UploadedFile::fake()->create('donor_pledge_letter.pdf', 150, 'application/pdf');

    $response = $this->actingAs($this->accountant)
        ->post(route('transactions.store'), [
            'type' => OperationalTransactionType::Donation->value,
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-01-15',
            'amount' => '1000.00',
            'financial_account_id' => $this->bank->id,
            'donation_category_id' => $this->generalCategory->id,
            'fund_id' => $this->restrictedProjectFund->id,
            'reference_number' => 'REF-ATTACH-001',
            'narration' => 'Donation with supporting document',
            'attachment' => $file,
        ]);

    $response->assertSessionHasNoErrors();
    $donation = OperationalTransaction::query()->where('reference_number', 'REF-ATTACH-001')->firstOrFail();
    expect($donation->attachment_path)->not->toBeNull();
    expect($donation->attachment_original_name)->toBe('donor_pledge_letter.pdf');
    Storage::disk('local')->assertExists($donation->attachment_path);
});

test('detail screen displays original filename and metadata for uploads that retain original name', function () {
    $storedPath = 'operational-finance/random_hash_123.pdf';
    Storage::disk('local')->put($storedPath, '%PDF-1.4 test document content');

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => 'donor_pledge_letter.pdf',
        'reference_number' => 'REF-SHOW-001',
        'narration' => 'Donation detail view check',
        'created_by' => $this->accountant->id,
    ]);

    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $donation));

    $response->assertOk();
    $response->assertSee('Supporting Document');
    $response->assertSee('donor_pledge_letter.pdf');
    $response->assertSee('PDF');
    $response->assertDontSee('random_hash_123.pdf');
    $response->assertSee('View Document');
    $response->assertSee('Download');
    $response->assertSee(route('transactions.attachment', $donation));
});

test('legacy attachment without original filename displays clear fallback Supporting Document (PDF) and hides storage path', function () {
    $storedPath = 'operational-finance/7ORpjTzdWUMyEQfQrotkPpheEFmZnG8k2SjFkOSQ.pdf';
    Storage::disk('local')->put($storedPath, '%PDF-1.4 legacy document content');

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => null,
        'reference_number' => 'UAT-DON-DNR-DOC-001',
        'narration' => 'Legacy donation without stored original name',
        'created_by' => $this->accountant->id,
    ]);

    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $donation));

    $response->assertOk();
    $response->assertSee('Supporting Document (PDF)');
    $response->assertDontSee('7ORpjTzdWUMyEQfQrotkPpheEFmZnG8k2SjFkOSQ.pdf');

    // Download uses sanitized fallback filename instead of raw storage hash
    $downloadResponse = $this->actingAs($this->accountant)
        ->get(route('transactions.attachment', $donation));
    $downloadResponse->assertOk();
    $downloadResponse->assertHeader('content-disposition', "attachment; filename=donation-{$donation->id}-supporting-document.pdf");
});

test('detail screen shows clear warning when attached file is missing from storage', function () {
    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => 'operational-finance/non_existent_file.pdf',
        'attachment_original_name' => 'important_donor_paper.pdf',
        'reference_number' => 'REF-MISSING-001',
        'narration' => 'Donation missing file check',
        'created_by' => $this->accountant->id,
    ]);

    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $donation));

    $response->assertOk();
    $response->assertSee('Supporting Document');
    $response->assertSee('Attached file is missing from storage.');
    $response->assertSee('important_donor_paper.pdf');
    $response->assertDontSee('non_existent_file.pdf');
});

test('authorized branch staff and auditors can download attachment with original or sanitized name', function () {
    $content = '%PDF-1.4 sample pdf content for download';
    $storedPath = 'operational-finance/random_auth_file.pdf';
    Storage::disk('local')->put($storedPath, $content);

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => 'Official Donor Restriction.pdf',
        'created_by' => $this->accountant->id,
    ]);

    // Branch accountant download
    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.attachment', $donation));
    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=Official_Donor_Restriction.pdf');

    // Auditor inline view
    $auditorResponse = $this->actingAs($this->auditor)
        ->get(route('transactions.attachment', ['transaction' => $donation, 'inline' => 1]));
    $auditorResponse->assertOk();
    $auditorResponse->assertHeader('content-disposition', 'inline; filename=Official_Donor_Restriction.pdf');
});

test('malicious or traversal characters in original filename are sanitized upon download', function () {
    $storedPath = 'operational-finance/sanitization_test.pdf';
    Storage::disk('local')->put($storedPath, '%PDF-1.4 file content');

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => '../../secret/donor$letter#01 (final).pdf',
        'created_by' => $this->accountant->id,
    ]);

    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.attachment', $donation));
    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=donor_letter_01__final_.pdf');
});

test('cross-branch and unauthorized users cannot download attachment', function () {
    $storedPath = 'operational-finance/protected_doc.pdf';
    Storage::disk('local')->put($storedPath, 'secret pdf content');

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => 'sensitive_agreement.pdf',
        'created_by' => $this->accountant->id,
    ]);

    // Cross-branch accountant
    $this->actingAs($this->otherAccountant)
        ->get(route('transactions.attachment', $donation))
        ->assertForbidden();

    // User without permission
    $this->actingAs($this->unauthorizedUser)
        ->get(route('transactions.attachment', $donation))
        ->assertForbidden();

    // Guest redirect
    auth()->logout();
    $this->get(route('transactions.attachment', $donation))
        ->assertRedirect(route('login'));
});

test('missing attachment on disk returns 404', function () {
    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => 'operational-finance/does_not_exist.pdf',
        'attachment_original_name' => 'missing_file.pdf',
        'created_by' => $this->accountant->id,
    ]);

    $this->actingAs($this->accountant)
        ->get(route('transactions.attachment', $donation))
        ->assertNotFound();
});

test('posting fails when donor-restricted mandatory attachment file is missing from storage', function () {
    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => 'operational-finance/missing_from_disk.pdf',
        'attachment_original_name' => 'missing_pledge.pdf',
        'created_by' => $this->accountant->id,
    ]);

    expect(fn () => $this->service->post($donation, $this->accountant))
        ->toThrow(ValidationException::class, 'Allocating a General Donation to a donor-restricted fund requires an attached donor-written restriction document.');
});

test('attachment and original filename are preserved upon draft cancellation', function () {
    $storedPath = 'operational-finance/cancelled_draft_doc.pdf';
    Storage::disk('local')->put($storedPath, '%PDF-1.4 file content to retain');

    $donation = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Donation,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bank->id,
        'donation_category_id' => $this->generalCategory->id,
        'fund_id' => $this->restrictedProjectFund->id,
        'amount' => 100,
        'attachment_path' => $storedPath,
        'attachment_original_name' => 'cancelled_donor_letter.pdf',
        'created_by' => $this->accountant->id,
    ]);

    $this->service->cancelDraft($donation, 'Accidental entry with attachment', $this->accountant);

    $donation->refresh();
    expect($donation->status)->toBe(OperationalTransactionStatus::Cancelled);
    expect($donation->attachment_path)->toBe($storedPath);
    expect($donation->attachment_original_name)->toBe('cancelled_donor_letter.pdf');
    Storage::disk('local')->assertExists($storedPath);

    // Cancelled transaction still shows original filename and permits download by authorized staff
    $response = $this->actingAs($this->accountant)
        ->get(route('transactions.show', $donation));
    $response->assertOk();
    $response->assertSee('cancelled_donor_letter.pdf');
    $response->assertSee('Download');

    $downloadResponse = $this->actingAs($this->accountant)
        ->get(route('transactions.attachment', $donation));
    $downloadResponse->assertOk();
    $downloadResponse->assertHeader('content-disposition', 'attachment; filename=cancelled_donor_letter.pdf');
});
