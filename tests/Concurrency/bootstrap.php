<?php

/*
 * Boots the application against the DISPOSABLE MySQL concurrency database
 * frc_acct_qa_concurrency_test, using only the restricted QA account from
 * .env.qa-mysql. The development .env credentials are never used. Before and
 * after booting, Tests\Qa\QaMysqlGuard proves the live connection is that
 * account on that database, with no access anywhere else; otherwise nothing runs.
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Qa\QaMysqlGuard;

const CONCURRENCY_SUFFIX = '_concurrency_test';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/** Print the guard's reason (never a password) and stop. */
function concurrencyRefuse(Throwable $exception): never
{
    fwrite(STDERR, 'Refusing to run: '.$exception->getMessage().PHP_EOL);
    exit(2);
}

function concurrencyDatabase(string $root = ''): string
{
    return QaMysqlGuard::CONCURRENCY_DATABASE;
}

/**
 * Verify the QA account directly, then boot Laravel on it and verify again.
 *
 * @return array{username: string} the verified QA account (no secrets)
 */
function concurrencyBoot(string $root): array
{
    try {
        QaMysqlGuard::refuseCachedConfiguration($root);
        $credentials = QaMysqlGuard::credentials($root);
        QaMysqlGuard::verify(QaMysqlGuard::connect($credentials, QaMysqlGuard::CONCURRENCY_DATABASE), QaMysqlGuard::CONCURRENCY_DATABASE, $credentials['username']);

        $overrides = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $credentials['host'], 'DB_PORT' => $credentials['port'], 'DB_DATABASE' => QaMysqlGuard::CONCURRENCY_DATABASE,
            'DB_USERNAME' => $credentials['username'], 'DB_PASSWORD' => $credentials['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ];
        foreach ($overrides as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        QaMysqlGuard::verifyLaravel(QaMysqlGuard::CONCURRENCY_DATABASE, $credentials['username']);
    } catch (Throwable $exception) {
        concurrencyRefuse($exception);
    }

    return ['username' => $credentials['username']];
}
