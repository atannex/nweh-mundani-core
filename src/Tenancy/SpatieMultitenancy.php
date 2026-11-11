<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tenancy;

use Spatie\Multitenancy\Models\Tenant;
use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;

/**
 * What the package needs to know about spatie/laravel-multitenancy. Every
 * method is safe to call when Spatie is not installed.
 */
final class SpatieMultitenancy
{
    /** The tenant_resolver value that turns the integration on. */
    public const string ALIAS = 'spatie';

    public static function installed(): bool
    {
        return class_exists(Tenant::class);
    }

    public static function enabled(): bool
    {
        return in_array(config('nweh-mundani.tenant_resolver'), [self::ALIAS, SpatieTenantResolver::class], true);
    }

    /**
     * The integration is on and Spatie gives each tenant its own database:
     * the nmc_* tables then belong to the landlord database.
     */
    public static function databasePerTenant(): bool
    {
        return self::enabled() && self::switchesDatabase();
    }

    /** Whether Spatie gives each tenant its own database. */
    public static function switchesDatabase(): bool
    {
        if (! self::installed()) {
            return false;
        }

        $tasks = config('multitenancy.switch_tenant_tasks', []);

        foreach (is_array($tasks) ? $tasks : [] as $key => $value) {
            $task = is_string($key) ? $key : $value;

            if (is_string($task) && is_a($task, SwitchTenantDatabaseTask::class, true)) {
                return true;
            }
        }

        return false;
    }

    public static function landlordConnection(): ?string
    {
        $name = config('multitenancy.landlord_database_connection_name') ?? config('database.default');

        return is_string($name) ? $name : null;
    }

    public static function tenantConnection(): ?string
    {
        $name = config('multitenancy.tenant_database_connection_name') ?? config('database.default');

        return is_string($name) ? $name : null;
    }

    /** The key of Spatie's current tenant, or null when none is current. */
    public static function currentKey(): string|int|null
    {
        if (! self::installed()) {
            return null;
        }

        $model = config('multitenancy.tenant_model', Tenant::class);
        $model = is_string($model) && method_exists($model, 'current') ? $model : Tenant::class;
        $key = $model::current()?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
