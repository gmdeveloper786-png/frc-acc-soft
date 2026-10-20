<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Qa\QaMysqlGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests reset the database, so refuse any connection other than
     * in-memory SQLite or the one allowlisted disposable MySQL database, before
     * anything is migrated. On MySQL the live connection must also prove it is
     * the restricted QA account (see tests/Qa/QaMysqlGuard.php); a MySQL QA run
     * that silently fell back to SQLite is refused too.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $qaRun = getenv('QA_MYSQL_RUN') === '1';

        if ($connection === 'sqlite' && $database === ':memory:' && ! $qaRun) {
            return $app;
        }
        if ($connection !== 'mysql' || $database !== QaMysqlGuard::PEST_DATABASE || ! $qaRun) {
            throw new RuntimeException('Refusing to run tests: the database is neither in-memory SQLite nor the allowlisted QA MySQL database '.QaMysqlGuard::PEST_DATABASE.' started through tests/Qa/run-mysql-pest.php.');
        }
        // The account must be the one named in .env.qa-mysql, not merely whatever the environment says.
        QaMysqlGuard::verifyLaravel($database, QaMysqlGuard::credentials($app->basePath())['username']);

        return $app;
    }
}
