<?php

/*
 * Runs Pest feature tests on MySQL/InnoDB, only on the DBA-created disposable
 * database frc_acct_qa_mysql_test, with the restricted QA account from
 * .env.qa-mysql (see tests/Qa/README.md).
 *
 *   php tests/Qa/run-mysql-pest.php --confirm-wipe=frc_acct_qa_mysql_test [tests/Feature/...Test.php ...]
 *
 * RefreshDatabase drops and recreates every table in that database, so the
 * confirmation is required. The QA account is verified here before Pest
 * starts, and Tests\TestCase verifies it again inside every test process. The
 * development .env credentials are never used and nothing outside the
 * allowlist is created, dropped or read.
 */

use Tests\Qa\QaMysqlGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

const DEFAULT_FILES = [
    'tests/Feature/FinancialAmountLimitTest.php',
    'tests/Feature/FinancialDisplayFormatTest.php',
    'tests/Feature/JournalEngineTest.php',
    'tests/Feature/JournalPostingSeparationTest.php',
    'tests/Feature/JournalReversalWorkflowTest.php',
    'tests/Feature/OpeningBalanceTest.php',
    'tests/Feature/OpeningBalanceSeparationTest.php',
    'tests/Feature/OpeningBalanceReturnTest.php',
    'tests/Feature/LedgerExportTest.php',
];

try {
    QaMysqlGuard::refuseCachedConfiguration($root);
    QaMysqlGuard::requireWipeConfirmation($argv, QaMysqlGuard::PEST_DATABASE);
    $credentials = QaMysqlGuard::credentials($root);
    QaMysqlGuard::verify(QaMysqlGuard::connect($credentials, QaMysqlGuard::PEST_DATABASE), QaMysqlGuard::PEST_DATABASE, $credentials['username']);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Refusing to run: '.$exception->getMessage().PHP_EOL);
    exit(2);
}

$requested = array_values(array_filter(array_slice($argv, 1), fn (string $argument): bool => ! str_starts_with($argument, '--')));
$files = $requested === [] ? DEFAULT_FILES : $requested;
foreach ($files as $file) {
    $real = realpath($root.DIRECTORY_SEPARATOR.$file);
    $features = realpath($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature');
    if ($real === false || $features === false || ! str_starts_with($real, $features.DIRECTORY_SEPARATOR) || ! str_ends_with($real, 'Test.php')) {
        fwrite(STDERR, "Refusing to run: {$file} is not a feature test file.".PHP_EOL);
        exit(2);
    }
}

// The child inherits the current environment plus explicit QA settings; real
// environment variables take precedence over .env and over phpunit.xml <env>.
$environment = [...getenv(), ...[
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
    'DB_HOST' => $credentials['host'], 'DB_PORT' => $credentials['port'], 'DB_DATABASE' => QaMysqlGuard::PEST_DATABASE,
    'DB_USERNAME' => $credentials['username'], 'DB_PASSWORD' => $credentials['password'],
    'QA_MYSQL_RUN' => '1',
]];

echo 'Running '.count($files).' test file(s) on MySQL database '.QaMysqlGuard::PEST_DATABASE.' as the verified QA account.'.PHP_EOL;
$process = proc_open([PHP_BINARY, $root.'/vendor/bin/pest', '--compact', ...$files], [1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
if (! is_resource($process)) {
    fwrite(STDERR, 'Could not start Pest.'.PHP_EOL);
    exit(2);
}
exit(proc_close($process));
