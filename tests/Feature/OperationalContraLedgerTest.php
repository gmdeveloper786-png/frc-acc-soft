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
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => FiscalYear::query()->value('id'), 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->pettyCashLedger = Account::factory()->create();
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::PettyCash->value, 'account_id' => $this->pettyCashLedger->id, 'updated_by' => $this->maker->id]);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->actingAs($this->maker);
});

function contraPayload(object $test, OperationalTransactionType $type, array $overrides = []): array
{
    $financial = in_array($type, [OperationalTransactionType::BankReceipt, OperationalTransactionType::BankPayment], true) ? $test->bank : $test->cash;

    return ['type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15', 'party_name' => 'Test party', 'amount' => '250.0000',
        'financial_account_id' => $financial->id, 'contra_account_id' => $test->expense->id, 'narration' => 'Contra ledger fixture', ...$overrides];
}

test('no operational transaction type accepts a cash, bank or petty cash ledger as its other side', function () {
    $service = app(OperationalTransactionService::class);
    $types = [OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt, OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment, OperationalTransactionType::PettyCash, OperationalTransactionType::Expense];

    foreach ($types as $type) {
        foreach ([$this->bank->account_id, $this->cash->account_id, $this->pettyCashLedger->id] as $controlled) {
            expect(fn () => $service->create(contraPayload($this, $type, ['contra_account_id' => $controlled]), $this->maker))
                ->toThrow(ValidationException::class, 'CV (contra) journal');
        }
    }
    expect(OperationalTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('a receipt cannot credit bank without approval but an ordinary receipt still posts', function () {
    $service = app(OperationalTransactionService::class);

    expect(fn () => $service->create(contraPayload($this, OperationalTransactionType::CashReceipt, ['contra_account_id' => $this->bank->account_id]), $this->maker))
        ->toThrow(ValidationException::class, 'cash-bank transfers');

    $receipt = $service->create(contraPayload($this, OperationalTransactionType::CashReceipt, ['contra_account_id' => $this->income->id]), $this->maker);
    $posted = $service->post($receipt, $this->maker);
    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($posted->journalEntry->lines()->where('credit', '>', 0)->value('account_id'))->toBe($this->income->id);
});

test('an accrued expense cannot use a controlled ledger as its payable account', function () {
    $payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $service = app(OperationalTransactionService::class);
    $accrued = ['settlement_type' => 'accrued', 'financial_account_id' => null];

    expect(fn () => $service->create(contraPayload($this, OperationalTransactionType::Expense, [...$accrued, 'payable_account_id' => $this->bank->account_id]), $this->maker))
        ->toThrow(ValidationException::class, 'CV (contra) journal')
        ->and($service->create(contraPayload($this, OperationalTransactionType::Expense, [...$accrued, 'payable_account_id' => $payable->id]), $this->maker)->payable_account_id)->toBe($payable->id);
});
