<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests;

use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;

/**
 * Spatie with a database per tenant: "testing" is the landlord, and the
 * "tenant" connection is switched to each tenant's own SQLite database.
 */
abstract class SpatieDatabasePerTenantTestCase extends SpatieTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('multitenancy.landlord_database_connection_name', 'testing');
        $app['config']->set('multitenancy.tenant_database_connection_name', 'tenant');
        $app['config']->set('multitenancy.switch_tenant_tasks', [SwitchTenantDatabaseTask::class]);
    }
}
