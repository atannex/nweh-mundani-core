<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\MultitenancyServiceProvider;

/** Runs the package behind spatie/laravel-multitenancy, one shared database. */
abstract class SpatieTestCase extends DatabaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MultitenancyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nweh-mundani.tenant_resolver', 'spatie');
        $app['config']->set('multitenancy.switch_tenant_tasks', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('database')->nullable();
            $table->timestamps();
        });
    }
}
