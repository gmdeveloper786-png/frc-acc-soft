<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Funds\FundBalanceSummary;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function (): void {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);
    $this->admin = User::factory()->create(['is_administrator' => true, 'is_active' => true]);
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->admin->branches()->attach($this->branch);

    $this->year = FiscalYear::factory()->create([
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);

    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->cashLedger = Account::factory()->create(['code' => '111101', 'name' => 'Cash', 'account_type' => AccountType::Asset, 'normal_balance' => 'debit']);
    $this->electricityLedger = Account::factory()->create(['code' => '53102', 'name' => 'Electricity', 'account_type' => AccountType::Expense, 'normal_balance' => 'debit']);
    $this->rentLedger = Account::factory()->create(['code' => '53101', 'name' => 'Rent', 'account_type' => AccountType::Expense, 'normal_balance' => 'debit']);
    $this->projectExpenseLedger = Account::factory()->create(['code' => '52201', 'name' => 'Therapy Materials', 'account_type' => AccountType::Expense, 'normal_balance' => 'debit']);
    $this->generalIncomeLedger = Account::factory()->create(['code' => '43101', 'name' => 'General Donations', 'account_type' => AccountType::Income, 'normal_balance' => 'credit']);
    $this->zakatIncomeLedger = Account::factory()->create(['code' => '43102', 'name' => 'Zakat Donations', 'account_type' => AccountType::Income, 'normal_balance' => 'credit']);
    $this->projectIncomeLedger = Account::factory()->create(['code' => '43105', 'name' => 'Project Donations', 'account_type' => AccountType::Income, 'normal_balance' => 'credit']);
    $this->accrualsLedger = Account::factory()->create(['code' => '21102', 'name' => 'Accrued Expenses', 'account_type' => AccountType::Liability, 'normal_balance' => 'credit']);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->accrualsLedger->id]);

    $this->genFund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general', 'is_active' => true]);
    $this->zktFund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat Fund', 'is_active' => true]);
    $this->dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR', 'name' => 'Donor Fund', 'is_active' => true]);
    $this->dnrUat1 = Fund::factory()->restricted('donor')->create(['code' => 'DNR-UAT1', 'name' => 'DEMO UAT Project', 'parent_id' => $this->dnrParent->id, 'is_active' => true]);

    $this->postingService = app(JournalPostingService::class);
    $this->reversalService = app(JournalReversalService::class);
    $this->summaryService = app(FundBalanceSummary::class);
});

function createTestJournal(object $test, string $date, array $lines, array $overrides = []): JournalEntry
{
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->firstOrFail()->id,
        'transaction_date' => $date,
        'fiscal_year_id' => $test->year->id,
        'accounting_period_id' => $test->period->id,
        'branch_id' => $test->branch->id,
        'narration' => 'Test journal',
        'status' => JournalStatus::Draft,
        'created_by' => $test->admin->id,
        ...$overrides,
    ]);

    foreach ($lines as $index => $line) {
        $journal->lines()->create([
            'account_id' => $line[0]->id,
            'line_number' => $index + 1,
            'debit' => $line[1],
            'credit' => $line[2],
            'fund_id' => ($line[3] ?? null)?->id,
        ]);
    }

    $journal->update(['status' => JournalStatus::Posted]);

    return $journal;
}

test('Fund Balance Summary aggregates No Fund lines accurately and reconciles with active and reversed journals', function (): void {
    // 1. Post unallocated general expenses (simulating PKR 16,000 active general expenses)
    // Entry A: Electricity 10,000 without fund
    $jeA = createTestJournal($this, '2026-01-15', [
        [$this->electricityLedger, '10000.0000', '0.0000', null],
        [$this->accrualsLedger, '0.0000', '10000.0000', null],
    ]);

    // Entry B: Rent 6,000 without fund
    $jeB = createTestJournal($this, '2026-02-15', [
        [$this->rentLedger, '6000.0000', '0.0000', null],
        [$this->accrualsLedger, '0.0000', '6000.0000', null],
    ]);

    // Entry C: Reversed journal without fund (1,000 reversed in same period)
    $jeC = createTestJournal($this, '2026-03-01', [
        [$this->electricityLedger, '1000.0000', '0.0000', null],
        [$this->cashLedger, '0.0000', '1000.0000', null],
    ]);
    $this->reversalService->reverse($jeC, $this->period, '2026-03-05', 'Correction reason for testing', $this->admin);

    // 2. Post Restricted Fund transactions (simulating ZKT, DNR-UAT1, and GEN)
    // ZKT Income: 2,000
    $jeZkt = createTestJournal($this, '2026-01-20', [
        [$this->cashLedger, '2000.0000', '0.0000', $this->zktFund],
        [$this->zakatIncomeLedger, '0.0000', '2000.0000', $this->zktFund],
    ]);

    // DNR-UAT1 Income: 3,000 & Expense: 200 -> Balance 2,800
    $jeDnrInc = createTestJournal($this, '2026-01-25', [
        [$this->cashLedger, '3000.0000', '0.0000', $this->dnrUat1],
        [$this->projectIncomeLedger, '0.0000', '3000.0000', $this->dnrUat1],
    ]);

    $jeDnrExp = createTestJournal($this, '2026-01-28', [
        [$this->projectExpenseLedger, '200.0000', '0.0000', $this->dnrUat1],
        [$this->cashLedger, '0.0000', '200.0000', $this->dnrUat1],
    ]);

    // GEN Income: 5,000
    $jeGen = createTestJournal($this, '2026-02-01', [
        [$this->cashLedger, '5000.0000', '0.0000', $this->genFund],
        [$this->generalIncomeLedger, '0.0000', '5000.0000', $this->genFund],
    ]);

    // 3. Build FundBalanceSummary
    $report = $this->summaryService->build($this->admin, [
        'branch_id' => 'all',
        'fiscal_year_id' => $this->year->id,
    ]);

    $rowsByLabel = collect($report['rows'])->keyBy('label');

    // Verify No Fund row: exactly 16,000 expenditure (10,000 + 6,000, while 1,000 reversal netted to 0)
    expect($rowsByLabel)->toHaveKey('No fund');
    $noFundRow = $rowsByLabel['No fund'];
    expect($noFundRow['period_income'])->toBe('0.0000')
        ->and($noFundRow['period_expenditure'])->toBe('16000.0000')
        ->and($noFundRow['period_balance'])->toBe('-16000.0000');

    // Verify Restricted Funds remain completely clean and unaffected
    $zktRow = $rowsByLabel[$this->zktFund->label()];
    expect($zktRow['period_income'])->toBe('2000.0000')
        ->and($zktRow['period_expenditure'])->toBe('0.0000')
        ->and($zktRow['period_balance'])->toBe('2000.0000');

    $dnrRow = $rowsByLabel[$this->dnrUat1->label()];
    expect($dnrRow['period_income'])->toBe('3000.0000')
        ->and($dnrRow['period_expenditure'])->toBe('200.0000')
        ->and($dnrRow['period_balance'])->toBe('2800.0000');

    $genRow = $rowsByLabel[$this->genFund->label()];
    expect($genRow['period_income'])->toBe('5000.0000')
        ->and($genRow['period_expenditure'])->toBe('0.0000')
        ->and($genRow['period_balance'])->toBe('5000.0000');
});
