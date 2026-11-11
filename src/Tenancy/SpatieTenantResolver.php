<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tenancy;

use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;

/**
 * Follows spatie/laravel-multitenancy: the current Spatie tenant is the
 * current tenant here, and no current tenant means the global scope. It is
 * asked on every call, so it follows tenant switches in queued jobs and
 * long-running workers.
 */
final class SpatieTenantResolver implements TenantResolver
{
    public function __construct()
    {
        if (! SpatieMultitenancy::installed()) {
            throw UnsafeRegistration::because('tenant_resolver is "spatie" but spatie/laravel-multitenancy is not installed. Run `composer require spatie/laravel-multitenancy`.');
        }
    }

    public function current(): string|int|null
    {
        return SpatieMultitenancy::currentKey();
    }
}
