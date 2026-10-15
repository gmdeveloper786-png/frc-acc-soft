<?php

/*
 * MySQL/InnoDB verification on DISPOSABLE databases only. The development
 * database is opened read-only once, to compare the financial_accounts schema.
 *
 *   php tests/MySql/run.php            migration checks, then the listed Pest files on MySQL
 *   php tests/MySql/run.php --no-pest  migration checks only
 *
 * Databases used (created and dropped here):
 *   <DB_DATABASE>_migration_test   migration up/down and constraint checks
 *   <DB_DATABASE>_mysql_test       Pest feature tests (RefreshDatabase)
 *   <DB_DATABASE>_guard_probe      proves the test guard refuses other names
 */

use App\Http\Requests\StoreFinancialAccountRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FinancialAccount;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const MIGRATION = '2026_10_18_000001_scope_financial_account_ledger_to_branch';
const PEST_FILES = [
    'tests/Feature/BranchCashLedgerTest.php', 'tests/Feature/FeePostingTest.php', 'tests/Feature/FeeSettlementMappingTest.php',
    'tests/Feature/AccountImportTest.php', 'tests/Feature/OperationalFinanceTest.php', 'tests/Feature/CashFlowReportTest.php',
    'tests/Feature/FinancialStatementTest.php', 'tests/Feature/LedgerExportTest.php', 'tests/Feature/AccountMappingTest.php',
    'tests/Feature/FrcReconciliationTest.php', 'tests/Feature/JournalBypassProtectionTest.php',
];

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
if (file_exists($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Refusing to run: a cached configuration exists.\n");
    exit(2);
}
$env = Dotenv::parse((string) file_get_contents($root.'/.env'));
if (($env['DB_CONNECTION'] ?? '') !== 'mysql' || blank($env['DB_DATABASE'] ?? null)) {
    fwrite(STDERR, "Refusing to run: the application is not configured for MySQL.\n");
    exit(2);
}
$devDatabase = $env['DB_DATABASE'];
$migrationDb = $devDatabase.'_migration_test';
$pestDb = $devDatabase.'_mysql_test';
$probeDb = $devDatabase.'_guard_probe';
foreach ([$migrationDb, $pestDb, $probeDb] as $name) {
    if ($name === $devDatabase || ! preg_match('/_(migration_test|mysql_test|guard_probe)$/', $name)) {
        fwrite(STDERR, "Refusing to run: unexpected database name.\n");
        exit(2);
    }
}
$php = PHP_BINARY;

$server = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'), $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$recreate = function (string $name) use ($server): void {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
};
$drop = fn (string $name) => $server->exec("DROP DATABASE IF EXISTS `{$name}`");

/** Runs a command with the given database; returns [exit code, output]. Secrets are never printed. */
$run = function (array $command, string $database, array $extraEnv = []) use ($root): array {
    $environment = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'], $extraEnv);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
};

$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = [$name, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? ' — '.$detail : '');
};

$indexes = function (PDO $db): array {
    $list = [];
    foreach ($db->query('SHOW INDEX FROM financial_accounts')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $list[$row['Key_name']]['unique'] = (int) $row['Non_unique'] === 0;
        $list[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
    }

    return $list;
};
$foreignKeys = function (PDO $db, string $schema): array {
    $statement = $db->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME');
    $statement->execute([$schema, 'financial_accounts']);

    return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
};
$normalisedCreate = fn (PDO $db): string => preg_replace('/ AUTO_INCREMENT=\d+/', '', $db->query('SHOW CREATE TABLE financial_accounts')->fetch(PDO::FETCH_NUM)[1]);
$sqlState = function (callable $callback): string {
    try {
        $callback();

        return 'accepted';
    } catch (PDOException $exception) {
        return (string) ($exception->errorInfo[1] ?? $exception->getCode());
    }
};

try {
    echo "1. Migration on a disposable MySQL database ({$migrationDb})\n";
    $recreate($migrationDb);
    [$code] = $run([$php, 'artisan', 'migrate', '--force'], $migrationDb);
    $check('all migrations apply on MySQL/InnoDB', $code === 0);
    [$code] = $run([$php, 'artisan', 'migrate:rollback', '--step=1', '--force'], $migrationDb);
    $check('rollback without shared ledgers succeeds', $code === 0);

    $db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $migrationDb), $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dev = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $devDatabase), $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // The development database is only read: its schema must match the disposable one at the same migration state.
    $devApplied = (int) $dev->query("SELECT COUNT(*) FROM migrations WHERE migration = '".MIGRATION."'")->fetchColumn() === 1;
    echo '  info development database: migration '.($devApplied ? 'applied' : 'pending').PHP_EOL;
    if (! $devApplied) {
        $check('pre-migration schema identical to the development database (read-only comparison)', $normalisedCreate($db) === $normalisedCreate($dev));
    }
    $before = $indexes($db);
    $check('before: single-column unique(account_id) exists', ($before['financial_accounts_account_id_unique']['unique'] ?? false) === true);

    // Existing records, inserted through the application against the disposable database.
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $migrationDb, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== $migrationDb) {
        throw new RuntimeException('Application is not connected to the disposable database.');
    }
    $branchA = Branch::factory()->create(['status' => 'publish']);
    $branchB = Branch::factory()->create(['status' => 'publish']);
    $branchC = Branch::factory()->create(['status' => 'publish']);
    $cashLedger = Account::factory()->create(['code' => '11102']);
    $bankLedger = Account::factory()->create(['code' => '11201']);
    FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Cash A', 'branch_id' => $branchA->id, 'account_id' => $cashLedger->id]);
    FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Bank A', 'branch_id' => $branchA->id, 'account_id' => $bankLedger->id, 'account_number' => 'SYNTHETIC-0001']);
    $snapshot = fn () => $db->query('SELECT id, type, name, account_id, branch_id, account_number, is_active FROM financial_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $existing = $snapshot();
    $check('old schema already rejects a second branch on one ledger', $sqlState(fn () => FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $branchB->id, 'account_id' => $cashLedger->id])) === '1062');

    [$code] = $run([$php, 'artisan', 'migrate', '--force'], $migrationDb);
    $check('migration applies on MySQL with existing records', $code === 0);
    if ($devApplied) {
        $check('migrated schema identical to the development database (read-only comparison)', $normalisedCreate($db) === $normalisedCreate($dev));
    }
    $after = $indexes($db);
    $check('old unique(account_id) removed', ! isset($after['financial_accounts_account_id_unique']));
    $check('new unique(account_id, branch_id) present', ($after['financial_accounts_account_branch_unique']['unique'] ?? false) && array_values($after['financial_accounts_account_branch_unique']['columns']) === ['account_id', 'branch_id']);
    $fks = $foreignKeys($db, $migrationDb);
    $check('foreign keys still in place', ($fks['account_id'] ?? null) === 'accounts' && ($fks['branch_id'] ?? null) === 'branches', json_encode($fks));
    $check('existing records unchanged (ids, ledgers, branches, encrypted numbers)', $snapshot() === $existing);

    $check('same ledger accepted in a second branch', $sqlState(fn () => FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Cash B', 'branch_id' => $branchB->id, 'account_id' => $cashLedger->id])) === 'accepted');
    $check('same ledger rejected twice in one branch (1062)', $sqlState(fn () => FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $branchA->id, 'account_id' => $cashLedger->id])) === '1062');
    $check('null branch rejected (1048), so it cannot bypass uniqueness', $sqlState(fn () => DB::table('financial_accounts')->insert(['type' => 'cash', 'name' => 'No branch', 'account_id' => $cashLedger->id, 'branch_id' => null, 'currency' => 'PKR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()])) === '1048');
    $check('unknown ledger rejected by the foreign key (1452)', $sqlState(fn () => FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $branchC->id, 'account_id' => 999999])) === '1452');

    $validate = function (array $data) {
        $request = StoreFinancialAccountRequest::create('/financial-accounts', 'POST', $data);
        $request->setContainer(app());

        return Validator::make($data, $request->rules(), $request->messages());
    };
    $base = ['name' => 'Branch C account', 'currency' => 'PKR'];
    $bankOnCash = $validate([...$base, 'type' => 'bank', 'bank_name' => 'Synthetic Bank', 'account_id' => $cashLedger->id, 'branch_id' => $branchC->id]);
    $check('cash/bank conflict on one ledger rejected by validation', $bankOnCash->fails() && str_contains($bankOnCash->errors()->first('account_id'), 'already used by cash accounts'));
    $duplicate = $validate([...$base, 'type' => 'cash', 'account_id' => $cashLedger->id, 'branch_id' => $branchA->id]);
    $check('same-branch duplicate rejected by validation', $duplicate->fails() && str_contains($duplicate->errors()->first('account_id'), 'already has a cash or bank account on this ledger'));
    $check('third branch on the shared cash ledger passes validation', $validate([...$base, 'type' => 'cash', 'account_id' => $cashLedger->id, 'branch_id' => $branchC->id])->passes());

    [$code, $output] = $run([$php, 'artisan', 'migrate:rollback', '--step=1', '--force'], $migrationDb);
    $check('unsafe rollback refused while a ledger is shared', $code !== 0 && str_contains($output, 'already shared'));
    $check('schema unchanged after the refused rollback', isset($indexes($db)['financial_accounts_account_branch_unique']) && (int) $db->query("SELECT COUNT(*) FROM migrations WHERE migration = '".MIGRATION."'")->fetchColumn() === 1);

    DB::table('financial_accounts')->where('name', 'Cash B')->delete();
    [$code] = $run([$php, 'artisan', 'migrate:rollback', '--step=1', '--force'], $migrationDb);
    $restored = $indexes($db);
    $check('rollback succeeds once no ledger is shared', $code === 0 && ($restored['financial_accounts_account_id_unique']['unique'] ?? false) && ! isset($restored['financial_accounts_account_branch_unique']));
    $check('records intact after rollback', $snapshot() === $existing);
    [$code] = $run([$php, 'artisan', 'migrate', '--force'], $migrationDb);
    $check('migration re-applies after rollback', $code === 0 && isset($indexes($db)['financial_accounts_account_branch_unique']));

    echo "\n2. Test guard on a non-disposable name ({$probeDb}, empty)\n";
    $recreate($probeDb);
    [$code, $output] = $run([$php, 'vendor/bin/pest', '--compact', 'tests/Feature/UatDefectRegressionTest.php'], $probeDb);
    $tables = (int) $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$probeDb}'")->fetchColumn();
    $check('tests refuse a database without the _mysql_test suffix and migrate nothing', $code !== 0 && str_contains($output, 'Refusing to run tests') && $tables === 0);

    if (! in_array('--no-pest', $argv, true)) {
        echo "\n3. Feature tests on MySQL/InnoDB ({$pestDb})\n";
        $recreate($pestDb);
        [$code, $output] = $run([$php, 'vendor/bin/pest', '--compact', ...PEST_FILES], $pestDb);
        $summary = json_decode((string) (preg_match('/\{"tool":"pest".*\}/', $output, $match) ? $match[0] : '{}'), true) ?: [];
        $check('feature tests pass on MySQL', $code === 0, sprintf('%s tests, %s passed, %s assertions', $summary['tests'] ?? '?', $summary['passed'] ?? '?', $summary['assertions'] ?? '?'));
        if ($code !== 0) {
            echo substr($output, -6000), "\n";
        }
    }
} finally {
    foreach ([$migrationDb, $pestDb, $probeDb] as $name) {
        $drop($name);
    }
    echo "\nDisposable databases dropped.\n";
}

$failed = array_filter($results, fn (array $result): bool => ! $result[1]);
printf("\n%d checks, %d failed\n", count($results), count($failed));
exit($failed === [] ? 0 : 1);
