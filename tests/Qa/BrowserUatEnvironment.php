<?php

namespace Tests\Qa;

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/**
 * Isolated environment for the local browser UAT server and its fixture script.
 *
 * Everything the application reads comes from this class: the restricted QA
 * account from .env.qa-mysql, the QA database frc_acct_qa_mysql_test, a QA-only
 * APP_KEY and a QA-only storage directory. The development .env is never
 * loaded (Laravel is pointed at an environment file that does not exist), and
 * any inherited process variable that could redirect configuration is refused.
 * Each check fails closed; passwords and keys are never printed.
 */
final class BrowserUatEnvironment
{
    public const DATABASE = QaMysqlGuard::PEST_DATABASE;

    public const HOST = '127.0.0.1';

    public const PORT = 8020;

    public const URL = 'http://127.0.0.1:8020';

    public const SESSION_COOKIE = 'frc_qa_browser_uat_session';

    /** Relative to the project root; must be gitignored. */
    public const STORAGE = 'storage/qa-browser-uat';

    public const KEY_FILE = 'app.key';

    public const CREDENTIALS_FILE = 'qa-credentials.txt';

    /** Laravel is told to read this file from the QA storage directory; it must never exist. */
    public const ABSENT_ENV_FILE = '.env.qa-browser-uat-none';

    public const DIRECTORIES = ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'tmp'];

    /** Inherited variables with these prefixes could redirect configuration. */
    private const GUARDED_PREFIXES = [
        'APP_', 'DB_', 'MYSQL_', 'SESSION_', 'CACHE_', 'QUEUE_', 'MAIL_', 'LOG_', 'BROADCAST_', 'FILESYSTEM_', 'VIEW_',
        'FRC_', 'AWS_', 'REDIS_', 'MEMCACHED_', 'POSTMARK_', 'RESEND_', 'SLACK_', 'PAPERTRAIL_', 'SQS_', 'DYNAMODB_',
        'BEANSTALKD_', 'ACCOUNTING_', 'PAYMENT_', 'PURCHASE_ORDER_', 'APPROVAL_', 'BUDGET_', 'AUTH_', 'LARAVEL_',
    ];

    public static function storagePath(string $root, string $path = ''): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE.($path === '' ? '' : '/'.$path));
    }

    /** Create the QA storage tree (filesystem only) and prove git ignores it. */
    public static function prepareStorage(string $root): void
    {
        foreach (self::DIRECTORIES as $directory) {
            $path = self::storagePath($root, $directory);
            if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
                QaMysqlGuard::refuse("Could not create the QA storage directory {$directory}.");
            }
        }
        self::assertStorageReady($root);
    }

    /** The QA storage tree exists, sits outside the development storage folders and is gitignored. */
    public static function assertStorageReady(string $root): void
    {
        foreach (self::DIRECTORIES as $directory) {
            if (! is_dir(self::storagePath($root, $directory))) {
                QaMysqlGuard::refuse('The QA storage directory is incomplete. Run the fixture script first.');
            }
        }
        foreach ([self::KEY_FILE, self::CREDENTIALS_FILE, 'logs/laravel.log', 'app/private/probe.xlsx'] as $probe) {
            $relative = self::STORAGE.'/'.$probe;
            $command = 'git -C '.escapeshellarg($root).' check-ignore -q '.escapeshellarg($relative);
            exec($command, $output, $status);
            if ($status !== 0) {
                QaMysqlGuard::refuse("{$relative} is not gitignored; QA files could be committed.");
            }
        }
    }

    /** Write a fresh QA-only application key. The value is never printed. */
    public static function generateKey(string $root): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        if (self::developmentKey($root) === $key) {
            QaMysqlGuard::refuse('Generated key collides with the development key.');
        }
        if (file_put_contents(self::storagePath($root, self::KEY_FILE), $key, LOCK_EX) === false) {
            QaMysqlGuard::refuse('Could not write the QA application key.');
        }
    }

    public static function key(string $root): string
    {
        $path = self::storagePath($root, self::KEY_FILE);
        $key = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if (preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', $key) !== 1 || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
            QaMysqlGuard::refuse('The QA application key is missing or malformed. Run the fixture script first.');
        }
        if (self::developmentKey($root) === $key) {
            QaMysqlGuard::refuse('The QA application key equals the development key.');
        }

        return $key;
    }

    /**
     * Refuse inherited variables that could redirect configuration. A value this
     * class itself set earlier in the same server process is accepted.
     *
     * @param  array<string, string>  $overrides
     */
    public static function assertCleanInheritedEnvironment(array $overrides): void
    {
        $sources = [getenv(), $_ENV, array_filter($_SERVER, 'is_string')];
        foreach ($sources as $variables) {
            foreach ($variables as $name => $value) {
                $name = (string) $name;
                if (! self::isGuarded($name)) {
                    continue;
                }
                if (! array_key_exists($name, $overrides) || (string) $value !== $overrides[$name]) {
                    QaMysqlGuard::refuse("Inherited environment variable {$name} is set. Start the QA server from a clean shell.");
                }
            }
        }
    }

    /**
     * The complete QA environment. Nothing else is read: the development .env is
     * never loaded, so every value Laravel sees comes from here or from a config default.
     *
     * @param  array{host: string, port: string, username: string, password: string}  $credentials
     * @return array<string, string>
     */
    public static function overrides(string $root, array $credentials, string $key): array
    {
        $storage = self::storagePath($root);

        return [
            'APP_NAME' => 'FRC Accounting QA UAT', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => $key, 'APP_PREVIOUS_KEYS' => '',
            'APP_URL' => self::URL, 'APP_MAINTENANCE_DRIVER' => 'file',
            // Relative to the project root: Laravel treats a Windows drive path as relative here.
            'APP_CONFIG_CACHE' => self::STORAGE.'/framework/config.php', 'APP_ROUTES_CACHE' => self::STORAGE.'/framework/routes.php',
            'APP_EVENTS_CACHE' => self::STORAGE.'/framework/events.php', 'APP_SERVICES_CACHE' => self::STORAGE.'/framework/services.php',
            'APP_PACKAGES_CACHE' => self::STORAGE.'/framework/packages.php',
            'LARAVEL_STORAGE_PATH' => $storage,
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $credentials['host'], 'DB_PORT' => $credentials['port'],
            'DB_DATABASE' => self::DATABASE, 'DB_USERNAME' => $credentials['username'], 'DB_PASSWORD' => $credentials['password'],
            'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql', 'SESSION_COOKIE' => self::SESSION_COOKIE,
            'SESSION_SECURE_COOKIE' => 'false', 'SESSION_LIFETIME' => '120',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log', 'BROADCAST_CONNECTION' => 'null',
            'LOG_CHANNEL' => 'single', 'LOG_STACK' => 'single', 'LOG_LEVEL' => 'debug', 'FILESYSTEM_DISK' => 'local',
            'VIEW_COMPILED_PATH' => $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'FRC_API_ENABLED' => 'false', 'FRC_API_BASE_URL' => '', 'FRC_API_TOKEN' => '',
            'FRC_ORGANIZATION_NAME' => 'QA Test Organisation', 'FRC_ORGANIZATION_SHORT_NAME' => 'QA',
            'PAYMENT_APPROVAL_THRESHOLD' => '150000.00', 'ACCOUNTING_INITIAL_OPENING_DATE' => '2026-01-01',
        ];
    }

    /** @param  array<string, string>  $overrides */
    public static function apply(array $overrides): void
    {
        foreach ($overrides as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * Point the application at QA storage and a missing environment file, and
     * register fail-closed checks that run before any service provider boots.
     */
    public static function isolate(Application $app, string $root, string $username): void
    {
        $app->useStoragePath(self::storagePath($root));
        $app->useEnvironmentPath(self::storagePath($root));
        $app->loadEnvironmentFrom(self::ABSENT_ENV_FILE);

        $app->afterBootstrapping(LoadEnvironmentVariables::class, function (Application $app): void {
            if (is_file($app->environmentFilePath()) || ! self::isInside($app->environmentFilePath(), $app->storagePath())) {
                QaMysqlGuard::refuse('An environment file would be loaded; the QA server reads no .env file.');
            }
        });
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($root, $username): void {
            self::assertConfiguration($app, $root, $username);
        });
    }

    /** Configuration-only checks (no database access) run before providers boot. */
    public static function assertConfiguration(Application $app, string $root, string $username): void
    {
        if (strcasecmp((string) config('database.connections.mysql.username'), $username) !== 0) {
            QaMysqlGuard::refuse('The configured database account is not the QA account.');
        }
        $storage = self::storagePath($root);
        $paths = [
            'storage path' => $app->storagePath(),
            'compiled views' => (string) config('view.compiled'),
            'cached config' => $app->getCachedConfigPath(),
            'cached routes' => $app->getCachedRoutesPath(),
            'cached services' => $app->getCachedServicesPath(),
            'cached packages' => $app->getCachedPackagesPath(),
            'file sessions' => (string) config('session.files'),
            'file cache' => (string) config('cache.stores.file.path'),
        ];
        foreach ((array) config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) === 'local') {
                $paths["disk {$name}"] = (string) ($disk['root'] ?? '');
            } elseif (($disk['driver'] ?? null) !== null && $name !== 's3') {
                QaMysqlGuard::refuse("Filesystem disk {$name} is not local storage.");
            }
        }
        foreach ((array) config('logging.channels') as $name => $channel) {
            if (isset($channel['path'])) {
                $paths["log channel {$name}"] = (string) $channel['path'];
            }
        }
        foreach ($paths as $label => $path) {
            if ($path === '' || ! self::isInside($path, $storage)) {
                QaMysqlGuard::refuse("The {$label} is not inside the QA storage directory.");
            }
        }
        if ($app->configurationIsCached() || file_exists($root.'/bootstrap/cache/config.php')) {
            QaMysqlGuard::refuse('A cached configuration exists; it could point elsewhere.');
        }

        $expected = [
            'app.env' => 'local', 'app.debug' => false, 'app.url' => self::URL,
            'database.default' => 'mysql', 'database.connections.mysql.database' => self::DATABASE, 'database.connections.mysql.url' => null,
            'session.driver' => 'database', 'session.connection' => 'mysql', 'session.cookie' => self::SESSION_COOKIE,
            'cache.default' => 'array', 'queue.default' => 'sync', 'mail.default' => 'log', 'logging.default' => 'single',
            'filesystems.default' => 'local', 'services.frc.enabled' => false, 'services.frc.token' => null,
        ];
        foreach ($expected as $key => $value) {
            $actual = config($key);
            if ($actual === '' && $value === null) {
                $actual = null;
            }
            if ($actual !== $value) {
                QaMysqlGuard::refuse("Configuration {$key} is not the QA value.");
            }
        }
        if (filled(config('services.frc.base_url'))) {
            QaMysqlGuard::refuse('The FRC API base URL must be empty for QA.');
        }
        if ((string) config('app.key') === '' || (string) config('app.key') === self::developmentKey($root)) {
            QaMysqlGuard::refuse('The application key is not the QA key.');
        }
        if (in_array((string) config('app.key'), (array) config('app.previous_keys'), true) || array_filter((array) config('app.previous_keys')) !== []) {
            QaMysqlGuard::refuse('Previous application keys must be empty for QA.');
        }
    }

    public static function isInside(string $path, string $directory): bool
    {
        $normalise = static fn (string $value): string => rtrim(strtolower(str_replace('\\', '/', $value)), '/');
        $resolved = realpath($path);
        $path = $normalise($resolved === false ? $path : $resolved);
        $resolvedDirectory = realpath($directory);
        $directory = $normalise($resolvedDirectory === false ? $directory : $resolvedDirectory);

        return ! str_contains($path, '/../') && ($path === $directory || str_starts_with($path, $directory.'/'));
    }

    private static function isGuarded(string $name): bool
    {
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The development key, compared but never returned to a caller that prints. */
    private static function developmentKey(string $root): ?string
    {
        $path = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($path)) {
            return null;
        }
        $key = Dotenv::parse((string) file_get_contents($path))['APP_KEY'] ?? null;

        return $key === null || $key === '' ? null : $key;
    }
}
