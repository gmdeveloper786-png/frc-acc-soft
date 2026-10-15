<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\BudgetStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\Budgets\BudgetService;
use App\Services\JournalWorkflowService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

/*
 * Maker-checker counts every user who edited a draft as a maker, using the
 * immutable audit trail, so an editor cannot approve what someone else
 * created and submitted.
 */

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
});

function editorTestUser(SystemRole $role, Branch $branch, array $permissions = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->givePermissionTo($permissions);
    $user->branches()->attach($branch);

    return $user;
}

test('a user who edited a journal draft cannot approve it after someone else submits it', function () {
    [$maker, $editor, $checker] = [editorTestUser(SystemRole::Administrator, $this->branch), editorTestUser(SystemRole::Administrator, $this->branch), editorTestUser(SystemRole::Administrator, $this->branch)];
    $workflow = app(JournalWorkflowService::class);
    $payload = fn (string $amount): array => [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->period->id, 'branch_id' => $this->branch->id, 'narration' => 'Editor fixture',
        'lines' => [['account_id' => $this->expense->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $this->income->id, 'debit' => '0', 'credit' => $amount]],
    ];
    $this->actingAs($maker);
    $journal = $workflow->create($payload('100.0000'), $maker);
    $workflow->update($journal, $payload('900.0000'), $editor);
    $workflow->submit($journal->fresh(), $maker);

    expect(AuditLog::query()->where('event', 'journal.edited')->where('auditable_id', $journal->id)->value('actor_id'))->toBe($editor->id)
        ->and(fn () => $workflow->approve($journal->fresh(), $editor))->toThrow(ValidationException::class, 'You edited this journal')
        ->and($journal->fresh()->status)->toBe(JournalStatus::Submitted);
    $this->actingAs($editor)->post(route('journals.approve', $journal))->assertSessionHasErrors('approval');

    expect($workflow->approve($journal->fresh(), $checker)->status)->toBe(JournalStatus::Approved);
});

test('a user who edited a purchase order draft cannot approve it after someone else submits it', function () {
    $maker = editorTestUser(SystemRole::Accountant, $this->branch);
    $editor = editorTestUser(SystemRole::Approver, $this->branch, ['purchase_orders.update']);
    $approver = editorTestUser(SystemRole::Approver, $this->branch);
    $service = app(PurchaseOrderService::class);
    $payload = fn (string $price): array => [
        'vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $this->branch->id, 'po_date' => '2026-01-10', 'description' => 'Editor fixture',
        'lines' => [['description' => 'Therapy mats', 'quantity' => '1.0000', 'unit_price' => $price]],
    ];
    $this->actingAs($maker);
    $order = $service->create($payload('100.0000'), $maker);
    $service->update($order, $payload('9000.0000'), $editor);
    $service->submit($order->fresh(), $maker);

    expect($service->canApprove($order->fresh(), $editor))->toBeFalse()
        ->and(fn () => $service->approve($order->fresh(), $editor))->toThrow(ValidationException::class, 'You edited this purchase order')
        ->and(fn () => $service->reject($order->fresh(), 'Self review', $editor))->toThrow(ValidationException::class, 'You edited this purchase order')
        ->and($service->canApprove($order->fresh(), $approver))->toBeTrue()
        ->and($service->approve($order->fresh(), $approver)->status)->toBe(PurchaseOrderStatus::Approved);
});

test('a user who edited budget lines cannot approve the budget after someone else submits it', function () {
    $maker = editorTestUser(SystemRole::Accountant, $this->branch);
    $editor = editorTestUser(SystemRole::Approver, $this->branch, ['budgets.view', 'budgets.update']);
    $approver = editorTestUser(SystemRole::Approver, $this->branch);
    $approver->givePermissionTo('budgets.approve');
    $editor->givePermissionTo('budgets.approve');
    $service = app(BudgetService::class);
    $payload = fn (string $amount): array => ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'service_id' => null, 'name' => 'Operating budget',
        'lines' => [['account_id' => $this->expense->id, 'accounting_period_id' => $this->period->id, 'amount' => $amount]]];
    $this->actingAs($maker);
    $budget = $service->create($payload('1000.0000'), $maker);
    $service->update($budget, $payload('5000.0000'), $editor);
    $service->submit($budget->fresh(), $maker);

    expect($service->canApprove($budget->fresh(), $editor))->toBeFalse()
        ->and(fn () => $service->approve($budget->fresh(), $editor))->toThrow(ValidationException::class, 'You edited this budget')
        ->and($service->canApprove($budget->fresh(), $approver))->toBeTrue()
        ->and($service->approve($budget->fresh(), $approver)->status)->toBe(BudgetStatus::Approved);
});
