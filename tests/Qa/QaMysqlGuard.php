<?php

namespace Tests\Qa;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Fail-closed guard shared by every MySQL QA runner.
 *
 * The runners may only ever touch two pre-created disposable databases, and
 * only through a restricted QA account read from .env.qa-mysql (never from
 * .env). Before anything is migrated, seeded or wiped, the live connection is
 * checked: driver, database, effective user, grants, active roles and the
 * schemas the account can see. Anything missing, unexpected or ambiguous stops
 * the run. Passwords are never printed.
 */
final class QaMysqlGuard
{
    public const CONCURRENCY_DATABASE = 'frc_acct_qa_concurrency_test';

    public const PEST_DATABASE = 'frc_acct_qa_mysql_test';

    public const ALLOWED_DATABASES = [self::CONCURRENCY_DATABASE, self::PEST_DATABASE];

    public const ENV_FILE = '.env.qa-mysql';

    /** Always refused, in addition to the development database named in .env. */
    private const FORBIDDEN_DATABASES = ['frc_accounting_software'];

    /** Schemas any MySQL account may see without privileges. */
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema'];

    /**
     * QA connection settings from .env.qa-mysql only. The development .env is
     * read solely to refuse its database name and account; none of its
     * credentials are ever used.
     *
     * @return array{host: string, port: string, username: string, password: string}
     */
    public static function credentials(string $root): array
    {
        $path = $root.DIRECTORY_SEPARATOR.self::ENV_FILE;
        if (! is_file($path)) {
            self::refuse(self::ENV_FILE.' is missing. Create it from '.self::ENV_FILE.'.example with the restricted QA account.');
        }
        self::assertGitIgnored($root);

        $values = Dotenv::parse((string) file_get_contents($path));
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                self::refuse(self::ENV_FILE." must set {$key}.");
            }
        }
        if ($values['DB_CONNECTION'] !== 'mysql') {
            self::refuse(self::ENV_FILE.' must use DB_CONNECTION=mysql.');
        }
        if (isset($values['DB_DATABASE']) && $values['DB_DATABASE'] !== '') {
            self::refuse(self::ENV_FILE.' must not set DB_DATABASE: each runner uses its own allowlisted database.');
        }
        if (isset($values['DB_URL']) && $values['DB_URL'] !== '') {
            self::refuse(self::ENV_FILE.' must not set DB_URL.');
        }
        $username = trim($values['DB_USERNAME']);
        if (in_array(strtolower($username), ['root', 'admin', 'mysql.sys', 'mysql.session'], true)) {
            self::refuse('The QA account must be a dedicated restricted user, not a privileged account.');
        }
        $development = self::developmentIdentity($root);
        if ($development['username'] !== null && strcasecmp($development['username'], $username) === 0) {
            self::refuse('The QA account is the development database account. Use the dedicated restricted QA user.');
        }

        return ['host' => trim($values['DB_HOST']), 'port' => trim($values['DB_PORT']), 'username' => $username, 'password' => $values['DB_PASSWORD']];
    }

    /** Raw connection straight to one allowlisted, pre-created database. */
    public static function connect(array $credentials, string $database): PDO
    {
        self::assertAllowed($database);
        try {
            return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $credentials['host'], $credentials['port'], $database), $credentials['username'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $exception) {
            // The PDO message can include the account name but never the password; keep only the error code.
            self::refuse("Cannot connect to {$database} as the QA account (error ".$exception->getCode().'). The DBA must create it first.');
        }
    }

    /**
     * Prove the live connection is the restricted QA account on the expected
     * database, with no global, wildcard, extra-database or role privileges.
     */
    public static function verify(PDO $pdo, string $database, string $username): void
    {
        self::assertAllowed($database);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            self::refuse('The connection is not MySQL.');
        }
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            self::refuse("The connection is not on {$database}.");
        }
        $current = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if (strcasecmp(explode('@', $current)[0], $username) !== 0) {
            self::refuse('The effective MySQL account is not the configured QA account.');
        }

        self::assertGrants($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN), $database);
        self::assertNoActiveRoles($pdo);
        self::assertVisibleSchemas($pdo->query('SELECT schema_name FROM information_schema.schemata')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every grant must be either the privilege-free USAGE line or a privilege
     * on one of the two disposable databases named exactly (escaped, so not a
     * wildcard), and the database in use must be among them.
     *
     * @param  list<string>  $grants  SHOW GRANTS output
     */
    public static function assertGrants(array $grants, string $database): void
    {
        self::assertAllowed($database);
        $grantedDatabases = [];
        foreach ($grants as $grant) {
            $grant = trim((string) $grant);
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /i', $grant) && ! preg_match('/WITH GRANT OPTION/i', $grant)) {
                continue;
            }
            if (preg_match('/^GRANT (.+) ON `([^`]+)`\.\* TO /i', $grant, $match) && ! preg_match('/WITH GRANT OPTION|PROXY/i', $grant)) {
                // In a database-level grant an unescaped _ or % is a wildcard; only an exact, escaped name counts.
                $exact = preg_match('/(?<!\\\\)[_%]/', $match[2]) === 0;
                $name = str_replace(['\\_', '\\%'], ['_', '%'], $match[2]);
                if ($exact && in_array($name, self::ALLOWED_DATABASES, true)) {
                    $grantedDatabases[] = $name;

                    continue;
                }
            }
            self::refuse('The QA account has a privilege outside the two disposable databases: '.self::describeGrant($grant));
        }
        if (! in_array($database, $grantedDatabases, true)) {
            self::refuse("The QA account has no explicit grant on {$database}.");
        }
    }

    /** @param list<string> $visible Schemas the account can see. */
    public static function assertVisibleSchemas(array $visible): void
    {
        foreach ($visible as $schema) {
            if (in_array($schema, [...self::FORBIDDEN_DATABASES, ...self::developmentDatabases()], true)) {
                self::refuse('The QA account can see the development database.');
            }
            if (! in_array($schema, [...self::ALLOWED_DATABASES, ...self::SYSTEM_SCHEMAS], true)) {
                self::refuse('The QA account can see a database outside the allowlist.');
            }
        }
    }

    /**
     * Inside a booted application: configuration, connection and live identity
     * must all point at the allowlisted database and the QA account.
     */
    public static function verifyLaravel(string $database, string $username): void
    {
        self::assertAllowed($database);
        $default = (string) config('database.default');
        if ($default !== 'mysql'
            || (string) config('database.connections.mysql.database') !== $database
            || strcasecmp((string) config('database.connections.mysql.username'), $username) !== 0
            || filled(config('database.connections.mysql.url'))) {
            self::refuse('The application configuration does not point at the QA database and account.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== $database) {
            self::refuse('The application connection is not the QA database.');
        }
        self::verify($connection->getPdo(), $database, $username);
    }

    /** Destructive setup needs --confirm-wipe=<exact database name>. */
    public static function requireWipeConfirmation(array $argv, string $database): void
    {
        self::assertAllowed($database);
        if (! in_array('--confirm-wipe='.$database, $argv, true)) {
            self::refuse("This run deletes every table in {$database}. Re-run with --confirm-wipe={$database} to confirm.");
        }
    }

    public static function refuseCachedConfiguration(string $root): void
    {
        if (file_exists($root.'/bootstrap/cache/config.php')) {
            self::refuse('A cached configuration exists (php artisan config:clear first); it could point elsewhere.');
        }
    }

    public static function assertAllowed(string $database): void
    {
        if (! in_array($database, self::ALLOWED_DATABASES, true) || in_array($database, [...self::FORBIDDEN_DATABASES, ...self::developmentDatabases()], true)) {
            self::refuse("{$database} is not one of the allowlisted disposable QA databases.");
        }
    }

    public static function refuse(string $message): never
    {
        throw new RuntimeException('QA MySQL guard: '.$message);
    }

    private static function assertGitIgnored(string $root): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['git', 'check-ignore', '-q', self::ENV_FILE], $descriptors, $pipes, $root);
        if (! is_resource($process)) {
            self::refuse('Cannot confirm that '.self::ENV_FILE.' is gitignored (git is unavailable).');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            self::refuse(self::ENV_FILE.' is not gitignored; add it to .gitignore before storing credentials in it.');
        }
    }

    private static function assertNoActiveRoles(PDO $pdo): void
    {
        try {
            $role = $pdo->query('SELECT CURRENT_ROLE()')->fetchColumn();
        } catch (Throwable) {
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            // Only MySQL before 8.0 has no roles at all; anything else must answer the question.
            if (! preg_match('/^5\.7\./', $version)) {
                self::refuse('Cannot determine the active roles of the QA account.');
            }

            return;
        }
        if (! in_array(strtoupper(trim((string) $role)), ['', 'NONE'], true)) {
            self::refuse('The QA account has an active role; grant privileges to the account directly instead.');
        }
    }

    /** @return array{username: ?string, database: ?string} */
    private static function developmentIdentity(string $root): array
    {
        $path = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($path)) {
            return ['username' => null, 'database' => null];
        }
        $values = Dotenv::parse((string) file_get_contents($path));

        return ['username' => ($values['DB_USERNAME'] ?? '') ?: null, 'database' => ($values['DB_DATABASE'] ?? '') ?: null];
    }

    /** @return list<string> */
    private static function developmentDatabases(): array
    {
        $database = self::developmentIdentity(dirname(__DIR__, 2))['database'];

        return $database === null ? [] : [$database];
    }

    /** A grant line without the account part, so no identity detail beyond the privilege is echoed. */
    private static function describeGrant(string $grant): string
    {
        return mb_substr((string) preg_replace('/\s+TO\s+.*$/i', '', $grant), 0, 120);
    }
}
