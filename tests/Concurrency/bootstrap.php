<?php

/*
 * Boots the application against a DISPOSABLE MySQL database for concurrency
 * tests. The database name is always "<development database>_concurrency_test"
 * and every script refuses to continue unless the live connection really
 * points at a name with that suffix. The development database is never used.
 */

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const CONCURRENCY_SUFFIX = '_concurrency_test';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if (file_exists($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Refusing to run: a cached configuration exists (run without config:cache).\n");
    exit(2);
}

/** @return array<string, string> Database settings from .env; never printed. */
function concurrencyEnv(string $root): array
{
    $values = Dotenv::parse((string) file_get_contents($root.'/.env'));
    if (($values['DB_CONNECTION'] ?? '') !== 'mysql' || blank($values['DB_DATABASE'] ?? null)) {
        fwrite(STDERR, "Refusing to run: the application is not configured for MySQL.\n");
        exit(2);
    }

    return $values;
}

function concurrencyDatabase(string $root): string
{
    return concurrencyEnv($root)['DB_DATABASE'].CONCURRENCY_SUFFIX;
}

function concurrencyBoot(string $root): void
{
    $overrides = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => concurrencyDatabase($root), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
    foreach ($overrides as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || ! str_ends_with((string) $connection->getDatabaseName(), CONCURRENCY_SUFFIX)) {
        fwrite(STDERR, "Refusing to run: the connection does not point at the disposable concurrency database.\n");
        exit(2);
    }
}
