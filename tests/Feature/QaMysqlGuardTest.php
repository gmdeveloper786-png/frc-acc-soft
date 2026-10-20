<?php

use Illuminate\Support\Facades\File;
use Tests\Qa\QaMysqlGuard;

// Logic checks for the MySQL QA guard. They need no MySQL server and never
// touch a database; credential tests use a throwaway git repository in the
// system temp directory, never the project's own files.

const QA_DB = QaMysqlGuard::PEST_DATABASE;

function guardRefuses(Closure $call, string $message = ''): void
{
    expect($call)->toThrow(RuntimeException::class, $message);
}

test('only the two named disposable databases are allowed', function () {
    QaMysqlGuard::assertAllowed(QaMysqlGuard::CONCURRENCY_DATABASE);
    QaMysqlGuard::assertAllowed(QaMysqlGuard::PEST_DATABASE);

    foreach (['frc_accounting_software', 'frc_accounting_software_mysql_test', 'frc_acct_qa_other_test', 'frc_acct_qa_mysql_test2', ''] as $database) {
        guardRefuses(fn () => QaMysqlGuard::assertAllowed($database), 'not one of the allowlisted');
    }
});

test('grants are accepted only for exact escaped database names with no global role or delegated privilege', function () {
    $usage = 'GRANT USAGE ON *.* TO `frc_acct_qa`@`localhost`';
    $exact = 'GRANT ALL PRIVILEGES ON `frc\_acct\_qa\_mysql\_test`.* TO `frc_acct_qa`@`localhost`';
    QaMysqlGuard::assertGrants([$usage, $exact], QA_DB);
    QaMysqlGuard::assertGrants([$usage, $exact, 'GRANT SELECT, INSERT ON `frc\_acct\_qa\_concurrency\_test`.* TO `frc_acct_qa`@`localhost`'], QA_DB);

    $refused = [
        'global privileges' => 'GRANT ALL PRIVILEGES ON *.* TO `frc_acct_qa`@`localhost`',
        'global select' => 'GRANT SELECT ON *.* TO `frc_acct_qa`@`localhost`',
        'wildcard pattern' => 'GRANT ALL PRIVILEGES ON `frc\_acct\_qa\_%`.* TO `frc_acct_qa`@`localhost`',
        'unescaped name is a wildcard' => 'GRANT ALL PRIVILEGES ON `frc_acct_qa_mysql_test`.* TO `frc_acct_qa`@`localhost`',
        'development database' => 'GRANT SELECT ON `frc\_accounting\_software`.* TO `frc_acct_qa`@`localhost`',
        'another database' => 'GRANT ALL PRIVILEGES ON `other\_app`.* TO `frc_acct_qa`@`localhost`',
        'role grant' => 'GRANT `qa_role`@`%` TO `frc_acct_qa`@`localhost`',
        'grant option' => 'GRANT ALL PRIVILEGES ON `frc\_acct\_qa\_mysql\_test`.* TO `frc_acct_qa`@`localhost` WITH GRANT OPTION',
        'proxy' => 'GRANT PROXY ON ``@`` TO `frc_acct_qa`@`localhost`',
        'table level' => 'GRANT SELECT ON `frc\_accounting\_software`.`users` TO `frc_acct_qa`@`localhost`',
    ];
    foreach ($refused as $case => $grant) {
        guardRefuses(fn () => QaMysqlGuard::assertGrants([$usage, $exact, $grant], QA_DB), 'privilege outside');
    }

    // A grant on the other disposable database is not a grant on this one.
    guardRefuses(fn () => QaMysqlGuard::assertGrants([$usage, 'GRANT ALL PRIVILEGES ON `frc\_acct\_qa\_concurrency\_test`.* TO `frc_acct_qa`@`localhost`'], QA_DB), 'no explicit grant');
    guardRefuses(fn () => QaMysqlGuard::assertGrants([$usage], QA_DB), 'no explicit grant');
});

test('the account may see only the allowlisted and system schemas', function () {
    QaMysqlGuard::assertVisibleSchemas(['information_schema', 'performance_schema', QA_DB, QaMysqlGuard::CONCURRENCY_DATABASE]);

    guardRefuses(fn () => QaMysqlGuard::assertVisibleSchemas(['information_schema', QA_DB, 'frc_accounting_software']), 'development database');
    guardRefuses(fn () => QaMysqlGuard::assertVisibleSchemas(['information_schema', QA_DB, 'mysql']), 'outside the allowlist');
});

test('destructive runs need the exact database name confirmed', function () {
    QaMysqlGuard::requireWipeConfirmation(['run.php', '--confirm-wipe='.QA_DB], QA_DB);

    guardRefuses(fn () => QaMysqlGuard::requireWipeConfirmation(['run.php'], QA_DB), '--confirm-wipe='.QA_DB);
    guardRefuses(fn () => QaMysqlGuard::requireWipeConfirmation(['run.php', '--confirm-wipe=frc_accounting_software'], QA_DB), '--confirm-wipe='.QA_DB);
    guardRefuses(fn () => QaMysqlGuard::requireWipeConfirmation(['run.php', '--confirm-wipe='.QA_DB], 'frc_accounting_software'), 'not one of the allowlisted');
});

test('QA credentials come only from a gitignored .env.qa-mysql and never from the development account', function () {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-guard-'.bin2hex(random_bytes(4));
    mkdir($root);
    exec('git -C '.escapeshellarg($root).' init -q', $output, $code);
    if ($code !== 0) {
        $this->markTestSkipped('git is not available to build the temporary repository.');
    }
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_DATABASE=frc_accounting_software\nDB_USERNAME=devuser\nDB_PASSWORD=dev-secret\n");
    $valid = "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_USERNAME=frc_acct_qa\nDB_PASSWORD=qa-secret\n";
    $write = fn (string $content) => file_put_contents($root.'/.env.qa-mysql', $content);

    guardRefuses(fn () => QaMysqlGuard::credentials($root), 'is missing');

    $write($valid);
    guardRefuses(fn () => QaMysqlGuard::credentials($root), 'not gitignored');
    file_put_contents($root.'/.gitignore', ".env\n.env.qa-mysql\n");

    expect(QaMysqlGuard::credentials($root))->toBe(['host' => '127.0.0.1', 'port' => '3306', 'username' => 'frc_acct_qa', 'password' => 'qa-secret']);

    $cases = [
        'missing password' => [str_replace('DB_PASSWORD=qa-secret', 'DB_PASSWORD=', $valid), 'DB_PASSWORD'],
        'not mysql' => [str_replace('DB_CONNECTION=mysql', 'DB_CONNECTION=sqlite', $valid), 'DB_CONNECTION=mysql'],
        'database in file' => [$valid."DB_DATABASE=frc_accounting_software\n", 'must not set DB_DATABASE'],
        'url in file' => [$valid."DB_URL=mysql://x\n", 'must not set DB_URL'],
        'root account' => [str_replace('DB_USERNAME=frc_acct_qa', 'DB_USERNAME=root', $valid), 'dedicated restricted user'],
        'development account' => [str_replace('DB_USERNAME=frc_acct_qa', 'DB_USERNAME=devuser', $valid), 'development database account'],
    ];
    foreach ($cases as $case => [$content, $message]) {
        $write($content);
        guardRefuses(fn () => QaMysqlGuard::credentials($root), $message);
    }

    // The refusal messages never carry a password.
    $write(str_replace('DB_USERNAME=frc_acct_qa', 'DB_USERNAME=root', $valid));
    try {
        QaMysqlGuard::credentials($root);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('qa-secret')->not->toContain('dev-secret');
    }

    File::deleteDirectory($root);
});

test('the test runner refuses MySQL outside the QA run and a QA run that is not on MySQL', function () {
    $source = file_get_contents(base_path('tests/TestCase.php'));

    expect($source)->toContain('QaMysqlGuard::PEST_DATABASE')->toContain("getenv('QA_MYSQL_RUN') === '1'")->toContain('QaMysqlGuard::verifyLaravel')
        ->and(file_get_contents(base_path('.gitignore')))->toContain('.env.qa-mysql');
});
