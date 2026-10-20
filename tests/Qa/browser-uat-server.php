<?php

/*
 * Router for the LOCAL browser UAT server on the disposable QA database
 * frc_acct_qa_mysql_test. Launch it with PHP's built-in server only (never
 * "php artisan serve"), from the project root:
 *
 *   php -d upload_tmp_dir=storage/qa-browser-uat/tmp -d sys_temp_dir=storage/qa-browser-uat/tmp
 *       -d log_errors=1 -d display_errors=0 -d error_log=storage/qa-browser-uat/logs/php-errors.log
 *       -S 127.0.0.1:8020 -t public tests/Qa/browser-uat-server.php
 *
 * On every request, before Laravel touches a database, this router:
 *  - accepts only the 127.0.0.1:8020 listener and local clients;
 *  - refuses a cached configuration and any inherited variable that could redirect it;
 *  - reads the restricted QA account from .env.qa-mysql only and proves, on a raw
 *    connection, that it is that account on frc_acct_qa_mysql_test with no other access;
 *  - boots Laravel without loading any .env file, on QA-only storage, key, session
 *    cookie and database sessions, with array cache, log mail and the FRC API disabled;
 *  - checks the loaded configuration before any provider boots, and the live
 *    connection again after boot.
 * Any failure stops the request with HTTP 503. Passwords and keys are never printed.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Tests\Qa\BrowserUatEnvironment;
use Tests\Qa\QaMysqlGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/** Stop the request without exposing anything but the guard's reason. */
$refuse = static function (string $reason): never {
    if (! headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    file_put_contents('php://stderr', '[QA browser UAT] refused: '.$reason.PHP_EOL);
    echo 'QA browser UAT server refused this request: '.$reason.PHP_EOL;
    exit;
};

if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, 'Run this router with the PHP built-in server (php -S 127.0.0.1:8020 -t public tests/Qa/browser-uat-server.php).'.PHP_EOL);
    exit(2);
}
if (($_SERVER['SERVER_NAME'] ?? '') !== BrowserUatEnvironment::HOST || (int) ($_SERVER['SERVER_PORT'] ?? 0) !== BrowserUatEnvironment::PORT) {
    $refuse('The QA server must listen on 127.0.0.1:8020 only.');
}
if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    $refuse('Only local clients may use the QA server.');
}

// Static assets from public/ only. The public/storage link points at development storage and is never served.
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (preg_match('#^/storage(/|$)#i', $path) === 1) {
    http_response_code(404);
    exit;
}
$public = realpath($root.DIRECTORY_SEPARATOR.'public');
$file = $path !== '/' ? realpath($root.DIRECTORY_SEPARATOR.'public'.$path) : false;
if ($file !== false && is_file($file) && $public !== false
    && str_starts_with(strtolower($file), strtolower($public.DIRECTORY_SEPARATOR))
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

try {
    // Temporary uploads and PHP errors must land in QA storage; these are fixed when the server starts.
    BrowserUatEnvironment::assertStorageReady($root);
    $temporary = BrowserUatEnvironment::storagePath($root, 'tmp');
    foreach (['upload_tmp_dir' => $temporary, 'sys_temp_dir' => $temporary, 'error_log' => BrowserUatEnvironment::storagePath($root, 'logs')] as $setting => $directory) {
        $value = (string) ini_get($setting);
        if ($value === '' || ! BrowserUatEnvironment::isInside($setting === 'error_log' ? dirname($value) : $value, $directory)) {
            QaMysqlGuard::refuse("PHP {$setting} is not inside the QA storage directory. Use the documented launch command.");
        }
    }
    if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
        QaMysqlGuard::refuse('display_errors must be off. Use the documented launch command.');
    }

    QaMysqlGuard::refuseCachedConfiguration($root);
    $credentials = QaMysqlGuard::credentials($root);
    $overrides = BrowserUatEnvironment::overrides($root, $credentials, BrowserUatEnvironment::key($root));
    BrowserUatEnvironment::assertCleanInheritedEnvironment($overrides);
    QaMysqlGuard::verify(QaMysqlGuard::connect($credentials, BrowserUatEnvironment::DATABASE), BrowserUatEnvironment::DATABASE, $credentials['username']);
    BrowserUatEnvironment::apply($overrides);
} catch (Throwable $exception) {
    $refuse($exception->getMessage());
}
unset($credentials['password'], $overrides);

header('X-QA-Environment: '.BrowserUatEnvironment::DATABASE);

// A visible marker on every HTML page, added here so the application views stay unchanged.
ob_start(static function (string $buffer): string {
    $html = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0) {
            $html = stripos($header, 'text/html') !== false;
        }
    }
    if (! $html) {
        return $buffer;
    }
    $banner = '<div role="status" style="position:sticky;top:0;z-index:2147483647;background:#b00020;color:#fff;'
        .'font:600 14px/1.4 system-ui,sans-serif;text-align:center;padding:6px 12px;letter-spacing:.04em">'
        .'QA TEST ENVIRONMENT &mdash; '.BrowserUatEnvironment::DATABASE.' &mdash; synthetic data only, not for real transactions</div>';

    return (string) preg_replace('/<body\b[^>]*>/i', '$0'.$banner, $buffer, 1);
});

define('LARAVEL_START', microtime(true));

try {
    /** @var Application $app */
    $app = require $root.'/bootstrap/app.php';
    $username = (string) getenv('DB_USERNAME');
    BrowserUatEnvironment::isolate($app, $root, $username);
    $app->booted(static function () use ($username): void {
        QaMysqlGuard::verifyLaravel(BrowserUatEnvironment::DATABASE, $username);
    });

    // Bootstrap here, inside the guard, so a refused configuration never reaches Laravel's
    // exception reporter (which would log through that configuration). Mirrors the kernel's own order.
    $request = Request::capture();
    $app->instance('request', $request);
    // Forget only the cached Request facade instance so it resolves the request bound above.
    Facade::clearResolvedInstance('request');
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $exception) {
    ob_end_clean();
    $refuse($exception->getMessage());
}

$response = $kernel->handle($request)->send();
$kernel->terminate($request, $response);
