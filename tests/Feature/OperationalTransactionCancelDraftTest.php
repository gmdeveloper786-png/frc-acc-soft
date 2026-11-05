<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);

    $this->admin = cancelOpUser(SystemRole::Administrator, $this->branch);
    $this->accountant = cancelOpUser(SystemRole::Accountant, $this->branch);
    $this->otherAccountant = cancelOpUser(SystemRole::Accountant, $this->otherBranch);
    $this->cashier = cancelOpUser(SystemRole::Cashier, $this->branch);
    $this->auditor = cancelOpUser(SystemRole::Auditor, $this->branch);

    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);

    $this->cashLedger = Account::factory()->create(['code' => '111101']);
    $this->bankLedger = Account::factory()->create(['code' => '111102']);
    $this->expenseLedger = Account::factory()->create(['code' => '511101', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->incomeLedger = Account::factory()->create(['code' => '411101', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableLedger->id]);
    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::DefaultExpense->value], ['account_id' => $this->expenseLedger->id]);

    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => $this->bankLedger]);

    $this->service = app(OperationalTransactionService::class);
});

function cancelOpUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function createDraftExpense(object $test, string $amount = '500.0000'): OperationalTransaction
{
    return $test->service->create([
        'type' => 'expense',
        'branch_id' => $test->branch->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Demo Payee',
        'amount' => $amount,
        'contra_account_id' => $test->expenseLedger->id,
        'settlement_type' => 'accrued',
        'reference_number' => 'REF-EXP-'.uniqid(),
        'narration' => 'Draft expense for cancellation testing',
    ], $test->accountant);
}

test('authorized user sees cancel draft form on draft expense', function () {
    $tx = createDraftExpense($this);

    expect($this->service->canCancelDraft($tx, $this->accountant))->toBeTrue()
        ->and($this->service->canCancelDraft($tx, $this->admin))->toBeTrue();

    $this->actingAs($this->accountant)->get(route('transactions.show', $tx))
        ->assertOk()
        ->assertSee('Cancel Draft')
        ->assertSee(route('transactions.cancel-draft', $tx), false)
        ->assertSee('Cancellation Reason');
});

test('authorized accountant can successfully cancel a draft transaction with audit trail', function () {
    $tx = createDraftExpense($this, '1500.0000');
    $reason = 'Created with wrong ledger code; cancelling draft to recreate.';

    $response = $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => $reason,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Transaction draft cancelled.');

    $tx->refresh();
    expect($tx->status)->toBe(OperationalTransactionStatus::Cancelled)
        ->and((float) $tx->amount)->toBe(1500.0)
        ->and($tx->journal_entry_id)->toBeNull();

    $log = AuditLog::query()
        ->where('auditable_type', $tx->getMorphClass())
        ->where('auditable_id', $tx->id)
        ->where('event', 'expenses.cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($this->accountant->id)
        ->and($log->properties['reason'])->toBe($reason)
        ->and($log->properties['from_status'])->toBe('draft');
});

test('original transaction record, party, reference, branch, and amounts are preserved upon cancellation', function () {
    $tx = createDraftExpense($this, '2500.0000');
    $creatorId = $tx->created_by;
    $ref = $tx->reference_number;
    $party = $tx->party_name;
    $branchId = $tx->branch_id;

    $this->service->cancelDraft($tx, 'Preservation test reason', $this->accountant);

    $tx->refresh();
    expect($tx->status)->toBe(OperationalTransactionStatus::Cancelled)
        ->and($tx->created_by)->toBe($creatorId)
        ->and($tx->reference_number)->toBe($ref)
        ->and($tx->party_name)->toBe($party)
        ->and($tx->branch_id)->toBe($branchId)
        ->and((float) $tx->amount)->toBe(2500.0);
});

test('cancellation requires a valid mandatory reason between 5 and 2000 characters', function () {
    $tx = createDraftExpense($this);

    // Empty reason
    $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    // Too short (< 5 chars)
    $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'bad',
    ])->assertSessionHasErrors('reason');

    // Service-level direct validation
    expect(fn () => $this->service->cancelDraft($tx, '1234', $this->accountant))
        ->toThrow(ValidationException::class);

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Draft);
});

test('unauthorized users without cancel permission cannot cancel draft transaction', function () {
    $tx = createDraftExpense($this);

    // Cashier without expenses.cancel
    $this->actingAs($this->cashier)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Unauthorized cancellation attempt',
    ])->assertForbidden();

    // Auditor without expenses.cancel
    $this->actingAs($this->auditor)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Unauthorized cancellation attempt',
    ])->assertForbidden();

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Draft);
});

test('cross branch user cannot cancel draft transaction of another branch', function () {
    $tx = createDraftExpense($this);

    $this->actingAs($this->otherAccountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Cross-branch cancellation attempt',
    ])->assertForbidden();

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Draft);
});

test('posted transaction cannot be cancelled via cancel draft', function () {
    $tx = createDraftExpense($this);
    // Submit, approve, post
    $approver = cancelOpUser(SystemRole::Approver, $this->branch);
    $this->service->submit($tx, $this->accountant);
    $this->service->approve($tx->fresh(), $approver);
    $this->service->post($tx->fresh(), $this->admin);

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Posted);

    $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Attempting to cancel posted transaction',
    ])->assertSessionHasErrors('status');

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Posted);
});

test('submitted or approved transaction cannot use draft cancellation', function () {
    $tx = createDraftExpense($this);
    $this->service->submit($tx, $this->accountant);

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);

    $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Attempting to cancel submitted transaction',
    ])->assertSessionHasErrors('status');

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

test('cancelled transaction cannot be submitted or posted', function () {
    $tx = createDraftExpense($this);
    $this->service->cancelDraft($tx, 'Cancelling draft', $this->accountant);

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Cancelled);

    expect(fn () => $this->service->submit($tx->fresh(), $this->accountant))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->post($tx->fresh(), $this->admin))
        ->toThrow(ValidationException::class);
});

test('repeated cancellation request is safely rejected without duplicate audit logs', function () {
    $tx = createDraftExpense($this);
    $this->service->cancelDraft($tx, 'Initial cancellation reason', $this->accountant);

    expect($tx->fresh()->status)->toBe(OperationalTransactionStatus::Cancelled);

    $this->actingAs($this->accountant)->post(route('transactions.cancel-draft', $tx), [
        'reason' => 'Second cancellation attempt',
    ])->assertSessionHasErrors('status');

    $logs = AuditLog::query()
        ->where('auditable_type', $tx->getMorphClass())
        ->where('auditable_id', $tx->id)
        ->where('event', 'expenses.cancelled')
        ->count();

    expect($logs)->toBe(1);
});

test('no journal entry or liability is created when draft transaction is cancelled', function () {
    $journalCountBefore = JournalEntry::count();
    $tx = createDraftExpense($this, '5000.0000');

    $this->service->cancelDraft($tx, 'Cancelled before submission', $this->accountant);

    expect(JournalEntry::count())->toBe($journalCountBefore)
        ->and($tx->fresh()->journal_entry_id)->toBeNull();
});

test('cancelled transaction displays reason, actor, and timestamp on detail view', function () {
    $tx = createDraftExpense($this, '3200.0000');
    $reason = 'Demo accidental draft cancel testing display';

    $this->service->cancelDraft($tx, $reason, $this->accountant);

    $this->actingAs($this->accountant)->get(route('transactions.show', $tx))
        ->assertOk()
        ->assertSee('cancelled')
        ->assertSee('This draft expense has been cancelled.')
        ->assertSee($reason)
        ->assertSee($this->accountant->name)
        ->assertDontSee('data-cancel-draft-section', false);
});

test('cancelling a draft vendor payment releases the reserved balance on the purchase bill', function () {
    $vendor = Vendor::factory()->create();
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create([
        'vendor_id' => $vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payableLedger->id,
        'bill_number' => 'PB-REL-'.uniqid(),
        'invoice_date' => '2026-01-15',
        'lines' => [[
            'account_id' => $this->expenseLedger->id,
            'description' => 'Test bill item',
            'quantity' => '1.0000',
            'unit_amount' => '1000.0000',
        ]],
    ], $this->accountant);

    $bills->submit($bill, $this->accountant);
    $approver = cancelOpUser(SystemRole::Approver, $this->branch);
    $bills->approve($bill->fresh(), $approver);
    $bills->post($bill->fresh(), $this->admin);

    $bill = $bill->fresh();
    expect($bill->status)->toBe(PurchaseBillStatus::Posted)
        ->and($bill->availableToPayAmount())->toBe('1000.0000');

    // Create a draft vendor payment
    $payment = $this->service->create([
        'type' => 'vendor_payment',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-16',
        'party_name' => $vendor->name,
        'amount' => '400.0000',
        'financial_account_id' => $this->bank->id,
        'purchase_bill_id' => $bill->id,
        'narration' => 'Draft vendor payment',
    ], $this->accountant);

    expect($bill->fresh()->availableToPayAmount())->toBe('600.0000');

    // Cancel the draft payment
    $this->service->cancelDraft($payment, 'Vendor requested bank transfer instead', $this->accountant);

    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Cancelled)
        ->and($bill->fresh()->availableToPayAmount())->toBe('1000.0000');
});
