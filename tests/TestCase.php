<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests reset the database, so refuse any connection other than in-memory
     * SQLite or a disposable "*_mysql_test" MySQL database before anything is migrated.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $safe = ($connection === 'sqlite' && $database === ':memory:') || ($connection === 'mysql' && str_ends_with($database, '_mysql_test'));
        if (! $safe) {
            throw new RuntimeException('Refusing to run tests: the database is neither in-memory SQLite nor a disposable *_mysql_test MySQL database.');
        }

        return $app;
    }
}
