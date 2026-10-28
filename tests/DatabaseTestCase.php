<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nweh-mundani.persistence.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
