<?php

/*
 * SYNTHETIC master data and drafts for a full manual UAT of every module.
 * Included by tests/Uat/setup.php after the base UAT data exists; never run
 * on its own. Everything goes through the application services, so their
 * validation, branch checks and audit logging apply.
 *
 * Deliberately NOT done here (the tester does it manually):
 *  - no posting policy is approved (fees, depreciation, payroll stay unapproved);
 *  - no journal is posted and no financial transaction leaves draft;
 *  - no payroll run is created;
 *  - fund ZKT keeps no permitted ledgers (to exercise the setup error).
 * The only recorded movements are quantity-only inventory receipts.
 *
 * Returns a summary array (counts per module) for setup.php to print.
 */

use App\Enums\OperationalTransactionType;
use App\Enums\PayrollComponentType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Assets\AssetCategoryService;
use App\Services\Assets\FixedAssetService;
use App\Services\AuditLogger;
use App\Services\Budgets\BudgetService;
use App\Services\Funds\FundService;
use App\Services\Funds\WorkbookFundCodes;
use App\Services\Inventory\InventoryCatalogService;
use App\Services\Inventory\InventoryService;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollComponentService;
use App\Services\PettyCash\ImprestFloatService;
use Illuminate\Support\Facades\DB;

return DB::transaction(function (): array {
    $admin = User::query()->where('email', 'uat.admin@uat.test')->firstOrFail();
    $accountant = User::query()->where('email', 'uat.accountant@uat.test')->firstOrFail();
    $branchA = Branch::query()->where('name', 'UAT Branch A')->firstOrFail();
    $branchB = Branch::query()->where('name', 'UAT Branch B')->firstOrFail();
    $ledger = fn (string $code): Account => Account::query()->where('code', $code)->firstOrFail();
    $financial = fn (string $name): FinancialAccount => FinancialAccount::query()->where('name', $name)->firstOrFail();
    $summary = [];

    // 1. Extra synthetic ledgers (classification-compatible with their parents).
    // 51105 is left free: the COA import fixture (coa-import.xlsx) introduces it.
    auth()->setUser($admin);
    $accounts = app(AccountService::class);
    foreach ([
        ['11205', 'UAT Staff Advances', '113', 'asset', 'debit'],
        ['12103', 'UAT Office Equipment', '121', 'asset', 'debit'],
        ['51106', 'UAT Medical Supplies', '511', 'expense', 'debit'],
    ] as [$code, $name, $parent, $type, $balance]) {
        $accounts->create(['code' => $code, 'name' => $name, 'level' => 'ledger', 'parent_id' => $ledger($parent)->id, 'account_type' => $type, 'normal_balance' => $balance, 'financial_statement' => $type === 'expense' ? 'income_expenditure' : 'balance_sheet', 'is_postable' => true, 'is_active' => true, 'sort_order' => (int) $code], $admin);
    }
    $summary['extra ledgers'] = '11205, 12103, 51106';

    // Master data below is entered by the accountant (maker); nothing is approved.
    auth()->setUser($accountant);

    // 2. Funds: workbook codes, grant sub-fund, permitted ledgers, donation category defaults.
    app(WorkbookFundCodes::class)->load($accountant);
    $funds = app(FundService::class);
    $fund = fn (string $code): Fund => Fund::query()->where('code', $code)->firstOrFail();
    $funds->create([
        'code' => 'GRT-01', 'name' => 'UAT Synthetic Grant', 'type' => Fund::TYPE_RESTRICTED, 'category' => Fund::CATEGORY_GRANT,
        'parent_id' => $fund('GRT')->id, 'restriction_notes' => 'UAT synthetic grant: supplies and utilities only',
        'is_active' => true, 'donor_name' => 'UAT Donor Foundation', 'grant_amount' => '500000.00',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'permitted_account_ids' => [$ledger('51101')->id, $ledger('51104')->id],
    ], $accountant);
    foreach (['DNR' => ['12101', '51101'], 'CAP' => ['12101']] as $code => $codes) {
        $existing = $fund($code);
        $funds->update($existing, [
            ...$existing->only(['code', 'name', 'type', 'category', 'parent_id', 'restriction_notes', 'is_active', 'donor_name', 'grant_amount']),
            'start_date' => $existing->start_date?->format('Y-m-d'), 'end_date' => $existing->end_date?->format('Y-m-d'),
            'permitted_account_ids' => array_map(fn (string $c): int => $ledger($c)->id, $codes),
        ], $accountant);
    }
    // There is no service for the category mapping; mirror DonationCategoryController::update.
    foreach (DonationCategory::query()->get() as $category) {
        $name = strtolower($category->name);
        $code = str_contains($name, 'zakat') ? 'ZKT' : (str_contains($name, 'project') ? 'CAP' : 'GEN');
        $category->update(['fund_id' => $fund($code)->id]);
        app(AuditLogger::class)->log('donation_categories.updated', $category, ['fund_id' => $category->fund_id, 'source' => 'uat-setup'], $accountant);
    }
    $summary['funds'] = Fund::query()->orderBy('code')->pluck('code')->implode(', ');

    // 3. Petty cash imprest float for Branch A on the petty cash mapping ledger 11204.
    app(ImprestFloatService::class)->create([
        'branch_id' => $branchA->id, 'account_id' => $ledger('11204')->id, 'float_amount' => '20000.00',
        'source_financial_account_id' => $financial('UAT Bank A')->id, 'custodian_name' => 'UAT Custodian',
    ], $accountant);
    $summary['imprest floats'] = 'UAT Branch A 20,000.00 on 11204 (source UAT Bank A)';

    // 4. Payroll: components (no tax rates) and three synthetic employees with salary structures.
    $componentService = app(PayrollComponentService::class);
    $componentService->create(['code' => 'HOUSE', 'name' => 'House Allowance', 'type' => PayrollComponentType::Earning->value, 'category' => 'allowance', 'account_id' => null, 'is_active' => true, 'sort_order' => 15], $accountant);
    $componentService->create(['code' => 'STAFF_ADV', 'name' => 'Staff Advance Recovery', 'type' => PayrollComponentType::Deduction->value, 'category' => 'advance', 'account_id' => $ledger('11205')->id, 'is_active' => true, 'sort_order' => 55], $accountant);
    $basic = PayrollComponent::query()->where('code', 'BASIC')->firstOrFail();
    $house = PayrollComponent::query()->where('code', 'HOUSE')->firstOrFail();
    $employees = app(EmployeeService::class);
    foreach ([
        ['UAT-E-001', 'UAT Employee A One', $branchA, 'UAT Therapist', '50000.00', '10000.00'],
        ['UAT-E-002', 'UAT Employee A Two', $branchA, 'UAT Therapy Assistant', '40000.00', '8000.00'],
        ['UAT-E-003', 'UAT Employee B One', $branchB, 'UAT Administrator', '35000.00', '7000.00'],
    ] as [$code, $name, $branch, $designation, $basicAmount, $houseAmount]) {
        $employee = $employees->create(['employee_code' => $code, 'name' => $name, 'branch_id' => $branch->id, 'designation' => $designation, 'employment_status' => 'active', 'joined_on' => '2026-01-01', 'notes' => 'Synthetic UAT employee'], $accountant);
        $employees->saveSalaryStructure($employee, [$basic->id => $basicAmount, $house->id => $houseAmount], $accountant);
    }
    $summary['payroll'] = 'components HOUSE + STAFF_ADV (11205) added; employees UAT-E-001/002 (Branch A), UAT-E-003 (Branch B); no runs';

    // 5. Inventory: category, one location per branch, two items, opening receipts in Branch A.
    $catalog = app(InventoryCatalogService::class);
    $category = $catalog->saveCategory(['name' => 'UAT Clinical Consumables', 'is_active' => true], $accountant);
    $storeA = $catalog->saveLocation(['branch_id' => $branchA->id, 'name' => 'UAT Store A', 'is_active' => true], $accountant);
    $catalog->saveLocation(['branch_id' => $branchB->id, 'name' => 'UAT Store B', 'is_active' => true], $accountant);
    $inventory = app(InventoryService::class);
    foreach ([['UAT-INV-001', 'UAT Gloves', 'box', '10', '50'], ['UAT-INV-002', 'UAT Therapy Putty', 'unit', '5', '30']] as [$code, $name, $unit, $minimum, $opening]) {
        $item = $catalog->saveItem(['item_code' => $code, 'name' => $name, 'category_id' => $category->id, 'unit_of_measure' => $unit, 'minimum_stock_level' => $minimum, 'description' => 'Synthetic UAT item', 'is_active' => true], $accountant);
        $inventory->receive(['item_id' => $item->id, 'branch_id' => $branchA->id, 'location_id' => $storeA->id, 'quantity' => $opening, 'transaction_date' => '2026-10-01', 'reference' => 'UAT-OPENING', 'notes' => 'Synthetic opening stock (quantity only)'], $accountant);
    }
    $summary['inventory'] = 'UAT Gloves 50 box, UAT Therapy Putty 30 unit in UAT Store A (Branch A)';

    // 6. Fixed assets: category and one ACTIVE asset (activation posts nothing).
    $assetCategory = app(AssetCategoryService::class)->create([
        'code' => 'UAT-THER', 'name' => 'UAT Therapy Equipment', 'description' => 'Synthetic UAT category', 'default_useful_life_months' => 60,
        'asset_account_id' => $ledger('12101')->id, 'accumulated_depreciation_account_id' => $ledger('12102')->id,
        'depreciation_expense_account_id' => $ledger('51103')->id, 'is_active' => true,
    ], $accountant);
    $assets = app(FixedAssetService::class);
    $asset = $assets->register([
        'name' => 'UAT Therapy Mat Set', 'category_id' => $assetCategory->id, 'description' => 'Synthetic UAT asset', 'branch_id' => $branchA->id,
        'acquisition_date' => '2026-01-01', 'in_service_date' => '2026-01-01', 'useful_life_months' => 60,
        'acquisition_cost' => '120000.00', 'residual_value' => '0', 'opening_accumulated_depreciation' => '0', 'location' => 'UAT Therapy Room', 'custodian' => 'UAT Custodian',
    ], $accountant);
    $assets->activate($asset, $accountant);
    $summary['fixed assets'] = $asset->refresh()->asset_code.' UAT Therapy Mat Set 120,000.00 '.$asset->status->value;

    // 7. Budget: one DRAFT for FY 2026, Branch A (annual amounts spread equally over the periods).
    $budgets = app(BudgetService::class);
    $year = FiscalYear::query()->where('start_date', '2026-01-01')->firstOrFail();
    $lines = [];
    foreach (['51101' => '60000', '51104' => '12000', '51102' => '1080000'] as $code => $annual) {
        foreach ($budgets->spreadAnnual($year, $annual) as $periodId => $amount) {
            $lines[] = ['account_id' => $ledger($code)->id, 'accounting_period_id' => $periodId, 'amount' => $amount];
        }
    }
    $budget = $budgets->create(['fiscal_year_id' => $year->id, 'branch_id' => $branchA->id, 'name' => 'UAT Draft Budget Branch A 2026', 'lines' => $lines], $accountant);
    $summary['budgets'] = "{$budget->name} ({$budget->status->value}, total {$budget->total_amount})";

    // 8. Draft transactions only (never submitted or posted).
    $transactions = app(OperationalTransactionService::class);
    $transactions->create([
        'type' => OperationalTransactionType::Expense->value, 'branch_id' => $branchA->id, 'transaction_date' => '2026-10-15',
        'party_name' => 'UAT Utility Provider', 'amount' => '3000.00', 'contra_account_id' => $ledger('51104')->id,
        'settlement_type' => 'accrued', 'reference_number' => 'UAT-EXP-DRAFT-1', 'narration' => 'UAT synthetic utilities bill (draft)',
    ], $accountant);
    $transactions->create([
        'type' => OperationalTransactionType::CashPayment->value, 'branch_id' => $branchA->id, 'transaction_date' => '2026-10-15',
        'party_name' => 'UAT Medical Supplies Co', 'amount' => '5000.00', 'financial_account_id' => $financial('UAT Cash A')->id,
        'contra_account_id' => $ledger('51101')->id, 'fund_id' => $fund('GRT-01')->id, 'reference_number' => 'UAT-CP-DRAFT-1',
        'narration' => 'UAT synthetic grant-funded supplies (draft)',
    ], $accountant);
    $summary['draft transactions'] = 'expense 3,000.00 (51104, accrued); cash payment 5,000.00 (51101, fund GRT-01)';

    return $summary;
});
