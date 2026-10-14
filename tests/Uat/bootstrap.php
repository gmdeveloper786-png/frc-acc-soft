<?php

/*
 * Boots the application against the ISOLATED UAT database
 * "<development database>_uat". Every UAT script refuses to continue unless
 * the live connection really points at a name with that suffix, so the
 * development and production databases are never touched.
 */

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const UAT_SUFFIX = '_uat';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if (file_exists($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Refusing to run: a cached configuration exists.\n");
    exit(2);
}

/** @return array<string, string> Database settings from .env; never printed. */
function uatEnv(string $root): array
{
    $values = Dotenv::parse((string) file_get_contents($root.'/.env'));
    if (($values['DB_CONNECTION'] ?? '') !== 'mysql' || blank($values['DB_DATABASE'] ?? null)) {
        fwrite(STDERR, "Refusing to run: the application is not configured for MySQL.\n");
        exit(2);
    }

    return $values;
}

function uatDatabase(string $root): string
{
    return uatEnv($root)['DB_DATABASE'].UAT_SUFFIX;
}

/** @return array<string, string> Environment overrides that point the app at the UAT database. */
function uatOverrides(string $root): array
{
    return ['APP_ENV' => 'local', 'APP_DEBUG' => 'true', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => uatDatabase($root), 'APP_URL' => 'http://127.0.0.1:8010', 'FRC_API_BASE_URL' => 'http://127.0.0.1:8011/api', 'FRC_API_TOKEN' => 'uat-synthetic-token', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
}

function uatBoot(string $root): void
{
    foreach (uatOverrides($root) as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || ! str_ends_with((string) $connection->getDatabaseName(), UAT_SUFFIX)) {
        fwrite(STDERR, "Refusing to run: the connection does not point at the UAT database.\n");
        exit(2);
    }
}
