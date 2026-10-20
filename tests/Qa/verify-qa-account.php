<?php

/*
 * Read-only verification of the restricted MySQL QA account. Run after the DBA
 * setup and before any QA runner:
 *
 *   php tests/Qa/verify-qa-account.php
 *
 * Positive: both allowlisted databases are reachable and pass every guard
 * check (account, grants, roles, visible schemas). Negative: the account is
 * actually REFUSED when it tries to open or read the development, UAT and
 * Laravel 10 databases; not seeing them is not taken as proof. Nothing is
 * created, changed or dropped, and no password is printed.
 */

use Tests\Qa\QaMysqlGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/** Databases the QA account must be denied, beyond the development database named in .env. */
const MUST_BE_DENIED = ['frc_accounting_software', 'frc_accounting_software_uat', 'frc_software_vs', 'mysql'];

/** Access denied (1044, 1142), unknown database (1049) or no such table (1146) all mean "cannot read". */
const DENIED_CODES = [1044, 1142, 1049, 1146];

$failures = 0;
$report = function (bool $passed, string $label) use (&$failures): void {
    echo ($passed ? '  PASS  ' : '  FAIL  ').$label.PHP_EOL;
    $failures += $passed ? 0 : 1;
};

try {
    $credentials = QaMysqlGuard::credentials($root);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Refusing to run: '.$exception->getMessage().PHP_EOL);
    exit(2);
}

foreach (QaMysqlGuard::ALLOWED_DATABASES as $database) {
    echo PHP_EOL.$database.PHP_EOL;
    try {
        $pdo = QaMysqlGuard::connect($credentials, $database);
        QaMysqlGuard::verify($pdo, $database, $credentials['username']);
        $report(true, 'reachable as the QA account; grants, roles and visible schemas are restricted');
        $report((string) $pdo->query('SELECT CURRENT_ROLE()')->fetchColumn() === 'NONE', 'no active role');
    } catch (Throwable $exception) {
        $report(false, $exception->getMessage());

        continue;
    }

    foreach (MUST_BE_DENIED as $forbidden) {
        foreach (["USE `{$forbidden}`", "SELECT 1 FROM `{$forbidden}`.`users` LIMIT 1"] as $attempt) {
            try {
                $pdo->query($attempt);
                $report(false, "{$attempt} was ALLOWED");
            } catch (PDOException $exception) {
                $code = (int) ($exception->errorInfo[1] ?? 0);
                $report(in_array($code, DENIED_CODES, true), "{$attempt} refused (MySQL error {$code})");
            }
        }
    }
    // Still on the QA database after the refused attempts.
    $report((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'connection remained on '.$database);
}

echo PHP_EOL.($failures === 0 ? 'QA account verified: access is limited to the two disposable databases.' : "{$failures} check(s) failed: do NOT run the QA runners.").PHP_EOL;
exit($failures === 0 ? 0 : 1);
