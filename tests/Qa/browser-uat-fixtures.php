<?php

/*
 * Wipes and rebuilds the DISPOSABLE QA database frc_acct_qa_mysql_test with
 * synthetic browser UAT fixtures. It never reads development records or
 * import files, and never touches any other database.
 *
 *   php tests/Qa/browser-uat-fixtures.php --confirm-wipe=frc_acct_qa_mysql_test
 *
 * Order: confirmation flag -> restricted QA account from .env.qa-mysql verified
 * on a raw connection -> QA storage and a fresh QA-only APP_KEY -> Laravel booted
 * without any .env file and verified again -> migrate:fresh -> permitted seeders ->
 * synthetic fixtures through the application services -> self-check -> one-time
 * credentials written to a gitignored local file. Any failure stops the run and
 * reports which steps completed. Passwords are never printed or logged.
 */

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialStatement;
use App\Enums\FiscalYearStatus;
use App\Enums\NormalBalance;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountService;
use App\Services\FiscalYearService;
use App\Services\OpeningBalanceValidationService;
use App\Support\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Qa\BrowserUatEnvironment;
use Tests\Qa\QaMysqlGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

const QA_SEEDERS = [AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class];

/** Synthetic users: role, branches (by fixture key) and the permissions the scenarios rely on. */
const QA_USERS = [
    'maker' => ['name' => 'QA Maker (Accountant)', 'email' => 'qa.maker@qa.test', 'role' => SystemRole::Accountant, 'branches' => ['one', 'two'], 'needs' => ['opening_balances.prepare', 'journals.create', 'journals.submit', 'ledgers.view', 'trial_balance.view']],
    'approver' => ['name' => 'QA Approver One', 'email' => 'qa.approver@qa.test', 'role' => SystemRole::Approver, 'branches' => ['two'], 'needs' => ['opening_balances.approve', 'journals.approve']],
    'reviewer' => ['name' => 'QA Approver Two', 'email' => 'qa.reviewer@qa.test', 'role' => SystemRole::Approver, 'branches' => ['two'], 'needs' => ['opening_balances.approve', 'journals.approve']],
    'poster' => ['name' => 'QA Poster (Accountant)', 'email' => 'qa.poster@qa.test', 'role' => SystemRole::Accountant, 'branches' => ['one', 'two'], 'needs' => ['opening_balances.post', 'journals.post', 'ledgers.view', 'trial_balance.view']],
    'admin' => ['name' => 'QA Administrator', 'email' => 'qa.admin@qa.test', 'role' => SystemRole::Administrator, 'branches' => ['one', 'two'], 'needs' => ['opening_balances.approve', 'journals.approve', 'users.update', 'branches.assign_users', 'branches.view_all', 'ledgers.view', 'trial_balance.view', 'audit_logs.view']],
];

const QA_BRANCHES = [
    'one' => ['frc_branch_id' => 990001, 'name' => 'QA Branch One', 'city' => 'QA City'],
    'two' => ['frc_branch_id' => 990002, 'name' => 'QA Branch Two', 'city' => 'QA City'],
];

/**
 * code => [name, type, level, parent code, postable, cash/bank eligibility]; statement and normal balance
 * follow the type. Codes follow the approved chart: 111 cash in hand (11102), 112 bank (11201),
 * 211 trade creditors (21101), 311 capital fund (31101). The cash/bank flag only classifies the
 * ledger; no financial account or GL mapping is created (Opening Balances need neither).
 */
const QA_ACCOUNTS = [
    '1' => ['QA Assets', AccountType::Asset, AccountLevel::ClassLevel, null, false, null],
    '11' => ['QA Current Assets', AccountType::Asset, AccountLevel::Group, '1', false, null],
    '111' => ['QA Cash in Hand', AccountType::Asset, AccountLevel::SubGroup, '11', false, null],
    '11102' => ['QA Cash in Hand - Branch Funds', AccountType::Asset, AccountLevel::Ledger, '111', true, FinancialAccountType::Cash],
    '112' => ['QA Cash at Bank', AccountType::Asset, AccountLevel::SubGroup, '11', false, null],
    '11201' => ['QA Bank Current Account', AccountType::Asset, AccountLevel::Ledger, '112', true, FinancialAccountType::Bank],
    '2' => ['QA Liabilities', AccountType::Liability, AccountLevel::ClassLevel, null, false, null],
    '21' => ['QA Current Liabilities', AccountType::Liability, AccountLevel::Group, '2', false, null],
    '211' => ['QA Trade Payables', AccountType::Liability, AccountLevel::SubGroup, '21', false, null],
    '21101' => ['QA Trade Creditors / Suppliers', AccountType::Liability, AccountLevel::Ledger, '211', true, null],
    '3' => ['QA Funds and Capital', AccountType::Equity, AccountLevel::ClassLevel, null, false, null],
    '31' => ['QA Capital and Reserves', AccountType::Equity, AccountLevel::Group, '3', false, null],
    '311' => ['QA Capital Fund', AccountType::Equity, AccountLevel::SubGroup, '31', false, null],
    '31101' => ['QA Capital Fund', AccountType::Equity, AccountLevel::Ledger, '311', true, null],
    '4' => ['QA Income', AccountType::Income, AccountLevel::ClassLevel, null, false, null],
    '41' => ['QA Operating Income', AccountType::Income, AccountLevel::Group, '4', false, null],
    '411' => ['QA Donations', AccountType::Income, AccountLevel::SubGroup, '41', false, null],
    '41101' => ['QA Donation Income', AccountType::Income, AccountLevel::Ledger, '411', true, null],
    '5' => ['QA Expenditure', AccountType::Expense, AccountLevel::ClassLevel, null, false, null],
    '51' => ['QA Operating Expenditure', AccountType::Expense, AccountLevel::Group, '5', false, null],
    '511' => ['QA Administration', AccountType::Expense, AccountLevel::SubGroup, '51', false, null],
    '51101' => ['QA Office Expenses', AccountType::Expense, AccountLevel::Ledger, '511', true, null],
];

/** The four ledgers the Opening Balance scenarios use. */
const QA_OPENING_BALANCE_LEDGERS = ['11102', '11201', '21101', '31101'];

$completed = [];
$step = 'confirmation';

/** Report the failing step and what completed (never a password), then stop. */
$fail = static function (string $step, Throwable $exception, array $completed): never {
    fwrite(STDERR, PHP_EOL.'FAILED at step: '.$step.PHP_EOL.'Reason: '.$exception->getMessage().PHP_EOL);
    fwrite(STDERR, 'Completed before the failure: '.($completed === [] ? 'nothing (no database change was made)' : implode(', ', $completed)).PHP_EOL);
    if (in_array('migrate:fresh', $completed, true)) {
        fwrite(STDERR, 'The QA database is only partially set up. Fix the cause and re-run the same command; it starts again from a clean wipe.'.PHP_EOL);
    }
    exit(2);
};

/** A strong random password with every character class; generated in memory only. */
$password = static function (): string {
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#%^*-_=+?'];
    $all = implode('', $sets);
    $characters = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
    while (count($characters) < 24) {
        $characters[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($characters) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
    }

    return implode('', $characters);
};

try {
    $arguments = array_slice($argv, 1);
    QaMysqlGuard::requireWipeConfirmation($arguments, BrowserUatEnvironment::DATABASE);
    if ($arguments !== ['--confirm-wipe='.BrowserUatEnvironment::DATABASE]) {
        QaMysqlGuard::refuse('Only --confirm-wipe='.BrowserUatEnvironment::DATABASE.' is accepted; no other option or database.');
    }

    $step = 'QA account verification';
    QaMysqlGuard::refuseCachedConfiguration($root);
    $credentials = QaMysqlGuard::credentials($root);
    QaMysqlGuard::verify(QaMysqlGuard::connect($credentials, BrowserUatEnvironment::DATABASE), BrowserUatEnvironment::DATABASE, $credentials['username']);
    $completed[] = 'QA account verified';

    $step = 'QA storage and key';
    BrowserUatEnvironment::prepareStorage($root);
    $credentialsFile = BrowserUatEnvironment::storagePath($root, BrowserUatEnvironment::CREDENTIALS_FILE);
    if (is_file($credentialsFile) && ! unlink($credentialsFile)) {
        QaMysqlGuard::refuse('Could not remove the previous QA credentials file.');
    }
    BrowserUatEnvironment::generateKey($root);
    $overrides = BrowserUatEnvironment::overrides($root, $credentials, BrowserUatEnvironment::key($root));
    BrowserUatEnvironment::assertCleanInheritedEnvironment([]);
    BrowserUatEnvironment::apply($overrides);
    unset($overrides);
    $completed[] = 'QA storage and key prepared';

    $step = 'Laravel boot on the QA database';
    $app = require $root.'/bootstrap/app.php';
    BrowserUatEnvironment::isolate($app, $root, $credentials['username']);
    $app->make(Kernel::class)->bootstrap();
    QaMysqlGuard::verifyLaravel(BrowserUatEnvironment::DATABASE, $credentials['username']);
    $completed[] = 'Laravel verified on '.BrowserUatEnvironment::DATABASE;

    $step = 'migrate:fresh';
    QaMysqlGuard::verifyLaravel(BrowserUatEnvironment::DATABASE, $credentials['username']);
    if (Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]) !== 0) {
        QaMysqlGuard::refuse('migrate:fresh failed: '.trim(Artisan::output()));
    }
    $completed[] = 'migrate:fresh';

    foreach (QA_SEEDERS as $seeder) {
        $step = 'seeder '.class_basename($seeder);
        QaMysqlGuard::verifyLaravel(BrowserUatEnvironment::DATABASE, $credentials['username']);
        if (Artisan::call('db:seed', ['--database' => 'mysql', '--class' => $seeder, '--force' => true]) !== 0) {
            QaMysqlGuard::refuse(class_basename($seeder).' failed: '.trim(Artisan::output()));
        }
        $completed[] = class_basename($seeder);
    }

    $step = 'synthetic fixtures';
    QaMysqlGuard::verifyLaravel(BrowserUatEnvironment::DATABASE, $credentials['username']);
    $secrets = [];
    DB::transaction(function () use (&$secrets, $password): void {
        $branches = [];
        foreach (QA_BRANCHES as $key => $branch) {
            $branches[$key] = Branch::query()->create([...$branch, 'address' => 'Synthetic QA address', 'phone' => '0000000000', 'status' => 'publish', 'last_synced_at' => null]);
        }

        $users = [];
        foreach (QA_USERS as $key => $definition) {
            $secret = $password();
            $user = User::query()->create([
                'name' => $definition['name'], 'email' => $definition['email'], 'password' => Hash::make($secret),
                'is_active' => true, 'is_administrator' => $definition['role'] === SystemRole::Administrator, 'password_changed_at' => now(),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($definition['role']->value);
            $user->branches()->attach(array_map(static fn (string $branch): int => $branches[$branch]->id, $definition['branches']));
            $users[$key] = $user;
            $secrets[$definition['email']] = $secret;
        }

        $accounts = app(AccountService::class);
        $created = [];
        $order = 0;
        foreach (QA_ACCOUNTS as $code => [$name, $type, $level, $parent, $postable, $cashOrBank]) {
            $incomeStatement = in_array($type, [AccountType::Income, AccountType::Expense], true);
            $created[$code] = $accounts->create([
                'code' => (string) $code, 'name' => $name, 'parent_id' => $parent === null ? null : $created[$parent]->id,
                'account_type' => $type->value, 'level' => $level->value,
                'normal_balance' => in_array($type, [AccountType::Asset, AccountType::Expense], true) ? NormalBalance::Debit->value : NormalBalance::Credit->value,
                'financial_statement' => $incomeStatement ? FinancialStatement::IncomeExpenditure->value : FinancialStatement::BalanceSheet->value,
                'financial_account_type' => $cashOrBank?->value, 'is_postable' => $postable, 'is_active' => true,
                'description' => 'Synthetic QA account', 'sort_order' => ++$order,
            ], $users['admin']);
        }

        $years = app(FiscalYearService::class);
        $year = $years->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $users['admin']);
        $years->open($year);
        $years->setCurrent($year->fresh());
    });
    $completed[] = 'synthetic fixtures';

    $step = 'fixture self-check';
    $year = FiscalYear::query()->where('name', 'FY 2026')->firstOrFail();
    $checks = [
        'FY 2026 is open and current' => $year->status === FiscalYearStatus::Open && $year->is_current,
        'FY 2026 has 12 open periods' => $year->periods()->count() === 12 && $year->periods()->where('status', '!=', AccountingPeriodStatus::Open->value)->doesntExist(),
        'two QA branches only' => Branch::query()->count() === 2,
        'five QA users only' => User::query()->count() === 5 && User::query()->where('email', 'not like', '%@qa.test')->doesntExist(),
        'synthetic accounts only' => Account::query()->count() === count(QA_ACCOUNTS) && Account::query()->where('name', 'not like', 'QA %')->doesntExist(),
        'six postable ledgers' => Account::query()->where('is_postable', true)->where('level', AccountLevel::Ledger->value)->count() === 6,
        'Opening Balance ledgers are exactly 11102, 11201, 21101, 31101' => app(OpeningBalanceValidationService::class)->eligibleAccounts()->orderBy('code')->pluck('code')->all() === QA_OPENING_BALANCE_LEDGERS,
        '11102 is cash and 11201 is bank' => Account::query()->where('code', '11102')->value('financial_account_type')?->value === 'cash'
            && Account::query()->where('code', '11201')->value('financial_account_type')?->value === 'bank',
        'no financial accounts or GL mappings' => DB::table('financial_accounts')->count() === 0 && DB::table('account_mappings')->count() === 0,
        'initial opening date equals the FY 2026 start' => app(AppSettings::class)->string('fiscal.initial_opening_date', '') === '2026-01-01' && $year->start_date->toDateString() === '2026-01-01',
        'January 2026 period is open' => $year->periods()->where('period_number', 1)->where('status', AccountingPeriodStatus::Open->value)->whereDate('start_date', '2026-01-01')->exists(),
        'JV voucher type is active' => DB::table('voucher_types')->where('code', 'JV')->where('is_active', true)->exists(),
        'no journals or opening balances' => DB::table('journal_entries')->count() === 0 && DB::table('opening_balance_batches')->count() === 0,
    ];
    foreach (QA_USERS as $definition) {
        $user = User::query()->where('email', $definition['email'])->firstOrFail();
        $checks[$definition['email'].' has role '.$definition['role']->value] = $user->hasRole($definition['role']->value) && $user->roles()->count() === 1;
        foreach ($definition['needs'] as $permission) {
            $checks[$definition['email'].' can '.$permission] = $user->can($permission);
        }
    }
    $approver = User::query()->where('email', 'qa.approver@qa.test')->firstOrFail();
    $checks['QA Approver One cannot post'] = ! $approver->can('journals.post') && ! $approver->can('opening_balances.post');
    $checks['QA Maker cannot approve'] = ! User::query()->where('email', 'qa.maker@qa.test')->firstOrFail()->can('opening_balances.approve');
    $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
    if ($failed !== []) {
        QaMysqlGuard::refuse('Fixture self-check failed: '.implode('; ', $failed));
    }
    $completed[] = 'self-check ('.count($checks).' checks)';

    $step = 'credentials file';
    $lines = ['# QA browser UAT logins for '.BrowserUatEnvironment::URL.' (synthetic users, '.BrowserUatEnvironment::DATABASE.' only).',
        '# Local file, gitignored. Do not share, commit or paste. Replaced on every fixture run.', ''];
    foreach (QA_USERS as $definition) {
        $lines[] = str_pad($definition['email'], 24).'  '.$secrets[$definition['email']].'  ('.$definition['role']->value.')';
    }
    if (file_put_contents($credentialsFile, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) {
        QaMysqlGuard::refuse('Could not write the QA credentials file.');
    }
    unset($secrets, $lines);
    BrowserUatEnvironment::assertStorageReady($root);
    $completed[] = 'credentials file';
} catch (Throwable $exception) {
    $fail($step, $exception, $completed);
}

echo 'QA browser UAT fixtures ready on '.BrowserUatEnvironment::DATABASE.PHP_EOL;
echo 'Completed: '.implode(', ', $completed).PHP_EOL.PHP_EOL;
foreach (QA_USERS as $definition) {
    $branchNames = implode(', ', array_map(static fn (string $branch): string => QA_BRANCHES[$branch]['name'], $definition['branches']));
    echo str_pad($definition['email'], 24).' '.str_pad($definition['role']->value, 22).' '.$branchNames.PHP_EOL;
}
echo PHP_EOL.'Passwords were NOT printed. Open this local file yourself (it is gitignored):'.PHP_EOL;
echo '  '.BrowserUatEnvironment::STORAGE.'/'.BrowserUatEnvironment::CREDENTIALS_FILE.PHP_EOL;
echo 'Ledgers: 11102 Cash, 11201 Bank, 21101 Trade Creditors / Payables, 31101 Capital Fund, 41101 Income, 51101 Expense.'.PHP_EOL;
echo 'FY 2026 open and current (12 open periods); opening date 2026-01-01. No financial accounts or GL mappings.'.PHP_EOL;
