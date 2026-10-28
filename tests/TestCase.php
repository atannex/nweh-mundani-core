<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests;

use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\NwehMundaniServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NwehMundaniServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['NwehMundani' => NwehMundani::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());
        $app['config']->set('cache.default', 'array');
        $app['config']->set('nweh-mundani.persistence.enabled', false);
    }

    /**
     * In-memory SQLite by default. CI can point the suite at MySQL or
     * PostgreSQL with NMC_TEST_DB=mysql|pgsql; the database name must
     * contain "test", because the persistence tests drop every table in it.
     *
     * @return array<string, mixed>
     */
    private static function connection(): array
    {
        $driver = getenv('NMC_TEST_DB') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        $database = (string) getenv('NMC_TEST_DB_DATABASE');

        if (! str_contains($database, 'test')) {
            throw new \RuntimeException("Refusing to run against database [{$database}]: its name must contain 'test'.");
        }

        return [
            'driver' => $driver,
            'host' => getenv('NMC_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('NMC_TEST_DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
            'database' => $database,
            'username' => getenv('NMC_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('NMC_TEST_DB_PASSWORD') ?: '',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_bin' : null,
            'prefix' => '',
        ];
    }
}
