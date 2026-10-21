<?php

use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->admin = makerCheckerUser(SystemRole::Administrator, $this->branch);
    $this->approver = makerCheckerUser(SystemRole::Approver, $this->branch);
    $this->accountant = makerCheckerUser(SystemRole::Accountant, $this->branch);
    $this->vendor = Vendor::factory()->create();
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bills = app(PurchaseBillService::class);
});

function makerCheckerUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A purchase bill created by $maker and, unless $submit is false, submitted by $submitter (default: the maker). */
function makerCheckerBill(object $test, User $maker, bool $submit = true, ?User $submitter = null): PurchaseBill
{
    $test->actingAs($maker);
    $bill = $test->bills->create([
        'vendor_id' => $test->vendor->id, 'branch_id' => $test->branch->id, 'payable_account_id' => $test->payable->id,
        'bill_number' => 'PB-MC-'.uniqid(), 'invoice_date' => '2026-01-15',
        'lines' => [['account_id' => $test->expense->id, 'description' => 'Maker-checker line', 'quantity' => '1.0000', 'unit_amount' => '1000.0000']],
    ], $maker);

    return $submit ? $test->bills->submit($bill, $submitter ?? $maker) : $bill;
}

function expectBillUnapproved(PurchaseBill $bill, PurchaseBillStatus $status = PurchaseBillStatus::Submitted): void
{
    $fresh = $bill->fresh();
    expect($fresh->status)->toBe($status)
        ->and($fresh->approved_by)->toBeNull()
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->total_amount)->toBe('1000.0000')
        ->and($fresh->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'purchase_bills.approved')->where('auditable_id', $bill->id)->exists())->toBeFalse();
}

test('the creator administrator cannot approve or return their own submitted bill through the service, the screen or a forged request', function () {
    $bill = makerCheckerBill($this, $this->admin);

    expect($this->bills->canApprove($bill, $this->admin))->toBeFalse()
        ->and($this->bills->canReturn($bill, $this->admin))->toBeFalse()
        ->and(fn () => $this->bills->approve($bill, $this->admin))->toThrow(ValidationException::class, 'apna bill approve nahi kar sakta')
        ->and(fn () => $this->bills->reject($bill, 'Self return attempt', $this->admin))->toThrow(ValidationException::class, 'apna bill reject nahi kar sakta');

    $this->actingAs($this->admin)->get(route('purchase-bills.show', $bill))->assertOk()
        ->assertDontSee(route('purchase-bills.approve', $bill), false)
        ->assertDontSee(route('purchase-bills.reject', $bill), false)
        ->assertSee('an independent approver must approve or return it');

    $this->post(route('purchase-bills.approve', $bill))->assertSessionHasErrors('approval');
    $this->post(route('purchase-bills.reject', $bill), ['reason' => 'Forged self return'])->assertSessionHasErrors('approval');

    expectBillUnapproved($bill);
    expect($bill->fresh()->rejection_reason)->toBeNull();
});

test('an independent approver sees approve and return and can approve the bill', function () {
    $bill = makerCheckerBill($this, $this->admin);

    expect($this->bills->canApprove($bill, $this->approver))->toBeTrue()
        ->and($this->bills->canReturn($bill, $this->approver))->toBeTrue();
    $this->actingAs($this->approver)->get(route('purchase-bills.show', $bill))->assertOk()
        ->assertSee(route('purchase-bills.approve', $bill), false)
        ->assertSee(route('purchase-bills.reject', $bill), false)
        ->assertDontSee('an independent approver must approve or return it');

    $this->post(route('purchase-bills.approve', $bill))->assertSessionHasNoErrors();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Approved)
        ->and((int) $bill->fresh()->approved_by)->toBe($this->approver->id)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'purchase_bills.approved')->where('auditable_id', $bill->id)->value('actor_id'))->toBe($this->approver->id);
});

test('an independent approver can return the bill for correction', function () {
    $bill = makerCheckerBill($this, $this->admin);

    $this->actingAs($this->approver)->post(route('purchase-bills.reject', $bill), ['reason' => 'Vendor invoice missing'])->assertSessionHasNoErrors();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Rejected)->and($bill->fresh()->approved_by)->toBeNull();
});

test('a user without the approval permission cannot approve', function () {
    $bill = makerCheckerBill($this, $this->admin);

    expect($this->bills->canApprove($bill, $this->accountant))->toBeFalse();
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))->assertOk()
        ->assertDontSee(route('purchase-bills.approve', $bill), false);
    $this->post(route('purchase-bills.approve', $bill))->assertForbidden();

    expectBillUnapproved($bill);
});

test('an approver without access to the bill branch cannot approve', function () {
    $bill = makerCheckerBill($this, $this->admin);
    $outsider = makerCheckerUser(SystemRole::Approver, Branch::factory()->create(['status' => 'publish']));

    expect($this->bills->canApprove($bill, $outsider))->toBeFalse();
    $this->actingAs($outsider)->get(route('purchase-bills.show', $bill))->assertForbidden();
    $this->post(route('purchase-bills.approve', $bill))->assertForbidden();

    expectBillUnapproved($bill);
});

test('a bill that is not submitted cannot be approved', function () {
    $draft = makerCheckerBill($this, $this->admin, submit: false);

    expect($this->bills->canApprove($draft, $this->approver))->toBeFalse();
    $this->actingAs($this->approver)->post(route('purchase-bills.approve', $draft))->assertSessionHasErrors('status');
    expectBillUnapproved($draft, PurchaseBillStatus::Draft);

    $approved = makerCheckerBill($this, $this->admin);
    $this->bills->approve($approved, $this->approver);
    $second = makerCheckerUser(SystemRole::Approver, $this->branch);
    expect($this->bills->canApprove($approved->fresh(), $second))->toBeFalse()
        ->and(fn () => $this->bills->approve($approved->fresh(), $second))->toThrow(ValidationException::class, 'eligible nahi')
        ->and((int) $approved->fresh()->approved_by)->toBe($this->approver->id);
});

test('the submitter and a user who edited the draft are makers and cannot approve', function () {
    $otherAdmin = makerCheckerUser(SystemRole::Administrator, $this->branch);

    // Created by the accountant, submitted by an administrator.
    $submitted = makerCheckerBill($this, $this->accountant, submitter: $this->admin);
    expect($this->bills->canApprove($submitted, $this->admin))->toBeFalse()
        ->and(fn () => $this->bills->approve($submitted, $this->admin))->toThrow(ValidationException::class);
    expectBillUnapproved($submitted);

    // Edited as a draft by another administrator, then submitted by the accountant.
    $edited = makerCheckerBill($this, $this->accountant, submit: false);
    $this->bills->update($edited, [
        'vendor_id' => $this->vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id,
        'bill_number' => $edited->bill_number, 'invoice_date' => '2026-01-15', 'expected_updated_at' => $edited->updated_at->format('Y-m-d H:i:s.u'),
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Edited line', 'quantity' => '1.0000', 'unit_amount' => '1000.0000']],
    ], $otherAdmin);
    $this->bills->submit($edited->fresh(), $this->accountant);

    expect($this->bills->preparerIds($edited->fresh()))->toContain($otherAdmin->id)
        ->and($this->bills->canApprove($edited->fresh(), $otherAdmin))->toBeFalse()
        ->and(fn () => $this->bills->approve($edited->fresh(), $otherAdmin))->toThrow(ValidationException::class);
    expectBillUnapproved($edited);
    expect($this->bills->canApprove($edited->fresh(), $this->approver))->toBeTrue();
});

test('the maker check is repeated on the locked row inside the approval transaction', function () {
    $bill = makerCheckerBill($this, $this->admin);

    // A stale in-memory copy that no longer names the creator must not get past the lock.
    $stale = $bill->fresh();
    $stale->created_by = $this->approver->id;
    $stale->submitted_by = $this->approver->id;
    $stale->setRelation('branch', $this->branch);

    expect(fn () => $this->bills->approve($stale, $this->admin))->toThrow(ValidationException::class, 'apna bill approve nahi kar sakta');
    expectBillUnapproved($bill);
});
