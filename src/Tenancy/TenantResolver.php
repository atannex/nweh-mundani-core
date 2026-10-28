<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tenancy;

/**
 * Tells the package which tenant the current request or job belongs to.
 * Bind your own implementation to connect any multi-tenancy library.
 */
interface TenantResolver
{
    /** The current tenant's key, or null for the global scope. */
    public function current(): string|int|null;
}
