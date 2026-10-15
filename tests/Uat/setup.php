<?php

/*
 * Builds the isolated UAT database with SYNTHETIC data only.
 *
 *   php tests/Uat/setup.php [--fresh]
 *
 * Creates "<dev database>_uat", migrates and seeds it, then adds UAT users for
 * every role, a small synthetic chart of accounts, branches, services, GL
 * mappings, cash/bank accounts, an open fiscal year and periods, vendors,
 * stock items and anonymised employees. A synthetic UAT Director is appointed
 * inside the UAT database only. No real financial data is used.
 */

use App\Enums\AccountMappingKey;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountMappingService;
use App\Services\AccountService;
use App\Services\ApprovalAuthorityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/bootstrap.php';
$root = dirname(__DIR__, 2);
$options = getopt('', ['fresh']);

$env = uatEnv($root);
$database = uatDatabase($root);
if (! str_ends_with($database, UAT_SUFFIX)) {
    exit("Refusing to run.\n");
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'), $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (isset($options['fresh'])) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

uatBoot($root);
Artisan::call('migrate', ['--force' => true]);
app(DatabaseSeeder::class)->run();

if (User::query()->where('email', 'uat.admin@uat.test')->exists()) {
    echo "UAT data already present in {$database}.".PHP_EOL;
    exit(0);
}

DB::transaction(function (): void {
    $password = Hash::make('Uat-Password-2026!');
    $branchA = Branch::query()->create(['frc_branch_id' => 990001, 'name' => 'UAT Branch A', 'city' => 'Lahore', 'status' => 'publish', 'last_synced_at' => now()]);
    $branchB = Branch::query()->create(['frc_branch_id' => 990002, 'name' => 'UAT Branch B', 'city' => 'Karachi', 'status' => 'publish', 'last_synced_at' => now()]);
    $therapy = FrcService::query()->create(['frc_service_id' => 990101, 'name' => 'UAT Speech Therapy', 'status' => 'publish', 'last_synced_at' => now()]);
    $schoolReadiness = FrcService::query()->create(['frc_service_id' => 990102, 'name' => 'UAT School Readiness Program', 'status' => 'publish', 'last_synced_at' => now()]);
    // Deliberately left without a fee income ledger: its synchronised payments must stay unposted.
    FrcService::query()->create(['frc_service_id' => 990103, 'name' => 'UAT Quran Teaching', 'status' => 'publish', 'last_synced_at' => now()]);

    $users = [];
    foreach ([
        'admin' => [SystemRole::Administrator, [$branchA, $branchB]], 'accountant' => [SystemRole::Accountant, [$branchA, $branchB]],
        'cashier' => [SystemRole::Cashier, [$branchA]], 'approver' => [SystemRole::Approver, [$branchA, $branchB]],
        'director' => [SystemRole::Director, [$branchA, $branchB]], 'board' => [SystemRole::Management, [$branchA, $branchB]],
        'auditor' => [SystemRole::Auditor, [$branchA, $branchB]], 'branchb' => [SystemRole::Accountant, [$branchB]],
    ] as $key => [$role, $branches]) {
        $user = User::query()->create(['name' => 'UAT '.ucfirst($key), 'email' => "uat.{$key}@uat.test", 'password' => $password, 'is_active' => true, 'is_administrator' => $role === SystemRole::Administrator, 'password_changed_at' => now()]);
        $user->assignRole($role->value);
        $user->branches()->sync(collect($branches)->pluck('id'));
        $users[$key] = $user;
    }
    // The UAT Director exists only in this isolated database.
    $directorRole = $users['director']->roles()->pluck('name')->all();
    $users['director']->syncRoles([SystemRole::Approver->value]);
    app(ApprovalAuthorityService::class)->provision($users['director'], true, 'Synthetic UAT Director for isolated testing only', 'uat-setup');
    $users['director']->syncRoles($directorRole);
    auth()->setUser($users['admin']);

    $accounts = app(AccountService::class);
    $created = [];
    $make = function (string $code, string $name, string $level, ?string $parent, string $type, string $balance) use ($accounts, $users, &$created): Account {
        $statement = in_array($type, ['income', 'expense'], true) ? 'income_expenditure' : 'balance_sheet';

        return $created[$code] = $accounts->create(['code' => $code, 'name' => $name, 'level' => $level, 'parent_id' => $parent ? $created[$parent]->id : null, 'account_type' => $type, 'normal_balance' => $balance, 'financial_statement' => $statement, 'is_postable' => $level === 'ledger', 'is_active' => true, 'sort_order' => (int) $code], $users['admin']);
    };
    $tree = [
        ['1', 'UAT Assets', 'class', null, 'asset', 'debit'], ['11', 'UAT Current Assets', 'group', '1', 'asset', 'debit'],
        ['111', 'UAT Cash and Bank', 'sub_group', '11', 'asset', 'debit'],
        ['11101', 'UAT Cash Branch A', 'ledger', '111', 'asset', 'debit'], ['11102', 'UAT Cash Branch B', 'ledger', '111', 'asset', 'debit'],
        ['11201', 'UAT Bank Branch A', 'ledger', '111', 'asset', 'debit'], ['11202', 'UAT Bank Branch B', 'ledger', '111', 'asset', 'debit'],
        ['11203', 'UAT Mobile Wallet Clearing', 'ledger', '111', 'asset', 'debit'], ['11204', 'UAT Petty Cash Branch A', 'ledger', '111', 'asset', 'debit'],
        ['113', 'UAT Receivables', 'sub_group', '11', 'asset', 'debit'], ['11301', 'UAT Client Fees Receivable', 'ledger', '113', 'asset', 'debit'],
        ['12', 'UAT Non-current Assets', 'group', '1', 'asset', 'debit'], ['121', 'UAT Equipment', 'sub_group', '12', 'asset', 'debit'],
        ['12101', 'UAT Therapy Equipment', 'ledger', '121', 'asset', 'debit'], ['12102', 'UAT Accumulated Depreciation', 'ledger', '121', 'asset', 'credit'],
        ['2', 'UAT Liabilities', 'class', null, 'liability', 'credit'], ['21', 'UAT Current Liabilities', 'group', '2', 'liability', 'credit'],
        ['211', 'UAT Payables', 'sub_group', '21', 'liability', 'credit'], ['21101', 'UAT Trade Creditors', 'ledger', '211', 'liability', 'credit'],
        ['21102', 'UAT Salary Payable', 'ledger', '211', 'liability', 'credit'], ['21103', 'UAT Tax Payable', 'ledger', '211', 'liability', 'credit'],
        ['3', 'UAT Fund', 'class', null, 'equity', 'credit'], ['31', 'UAT General Fund', 'group', '3', 'equity', 'credit'],
        ['311', 'UAT Accumulated Fund', 'sub_group', '31', 'equity', 'credit'], ['31101', 'UAT Opening Fund', 'ledger', '311', 'equity', 'credit'],
        ['31102', 'UAT Accumulated Surplus', 'ledger', '311', 'equity', 'credit'],
        ['4', 'UAT Income', 'class', null, 'income', 'credit'], ['41', 'UAT Service Income', 'group', '4', 'income', 'credit'],
        ['411', 'UAT Fee Income', 'sub_group', '41', 'income', 'credit'], ['41101', 'UAT Fee Income General', 'ledger', '411', 'income', 'credit'],
        ['41102', 'UAT Speech Therapy Fees', 'ledger', '411', 'income', 'credit'], ['41103', 'UAT Day Programme Fees', 'ledger', '411', 'income', 'credit'],
        ['412', 'UAT Donations', 'sub_group', '41', 'income', 'credit'], ['41201', 'UAT General Donations', 'ledger', '412', 'income', 'credit'],
        ['41202', 'UAT Zakat Donations', 'ledger', '412', 'income', 'credit'], ['41203', 'UAT New Project Donations', 'ledger', '412', 'income', 'credit'],
        ['5', 'UAT Expenses', 'class', null, 'expense', 'debit'], ['51', 'UAT Operating Expenses', 'group', '5', 'expense', 'debit'],
        ['511', 'UAT Running Costs', 'sub_group', '51', 'expense', 'debit'], ['51101', 'UAT Supplies', 'ledger', '511', 'expense', 'debit'],
        ['51102', 'UAT Salaries', 'ledger', '511', 'expense', 'debit'], ['51103', 'UAT Depreciation Expense', 'ledger', '511', 'expense', 'debit'],
        ['51104', 'UAT Utilities', 'ledger', '511', 'expense', 'debit'],
    ];
    foreach ($tree as $row) {
        $make(...$row);
    }

    foreach ([['cash', 'UAT Cash A', $branchA, '11101'], ['cash', 'UAT Cash B', $branchB, '11102'], ['bank', 'UAT Bank A', $branchA, '11201'], ['bank', 'UAT Bank B', $branchB, '11202']] as [$type, $name, $branch, $code]) {
        FinancialAccount::query()->create(['type' => $type, 'name' => $name, 'account_id' => $created[$code]->id, 'branch_id' => $branch->id, 'bank_name' => $type === 'bank' ? 'UAT Test Bank' : null, 'currency' => 'PKR', 'is_active' => true, 'created_by' => $users['admin']->id]);
    }

    $mappings = app(AccountMappingService::class);
    foreach ([[AccountMappingKey::AccountsReceivable, '11301'], [AccountMappingKey::AccountsPayable, '21101'], [AccountMappingKey::FeeIncomeGeneral, '41101'], [AccountMappingKey::DefaultExpense, '51101'], [AccountMappingKey::SalaryExpense, '51102'], [AccountMappingKey::SalaryPayable, '21102']] as [$key, $code]) {
        $mappings->set($key, $created[$code]->id, $users['admin']);
    }
    $mappings->set(AccountMappingKey::PettyCash, $created['11204']->id, $users['admin']);
    if ($accumulated = AccountMappingKey::tryFrom('accumulated_surplus')) {
        $mappings->set($accumulated, $created['31102']->id, $users['admin']);
    }
    $mappings->setServiceRevenue($therapy, $created['41102']->id, $users['admin']);
    $mappings->setServiceRevenue($schoolReadiness, $created['41103']->id, $users['admin']);
    foreach (DonationCategory::query()->get() as $category) {
        $category->update(['income_account_id' => $created[match (true) {
            str_contains(strtolower($category->name), 'zakat') => '41202', str_contains(strtolower($category->name), 'project') => '41203', default => '41201',
        }]->id]);
    }

    $year = FiscalYear::query()->where('start_date', '2026-01-01')->firstOrFail();
    $year->update(['status' => 'open', 'is_current' => true]);
    AccountingPeriod::query()->where('fiscal_year_id', $year->id)->update(['status' => 'open']);

    Vendor::query()->create(['vendor_code' => 'UAT-V-001', 'name' => 'UAT Medical Supplies Co', 'is_active' => true, 'created_by' => $users['admin']->id]);
    Vendor::query()->create(['vendor_code' => 'UAT-V-002', 'name' => 'UAT Stationers', 'is_active' => true, 'created_by' => $users['admin']->id]);
});

@unlink(base_path('storage/uat/fake-frc-extra.json')); // the synthetic FRC API starts from its base data set

echo "UAT database {$database} is ready. Users: uat.<role>@uat.test (synthetic password in tests/Uat/README).".PHP_EOL;
