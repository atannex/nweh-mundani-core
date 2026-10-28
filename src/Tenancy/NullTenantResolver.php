<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tenancy;

/** Single-tenant installations: everything lives in the global scope. */
final class NullTenantResolver implements TenantResolver
{
    public function current(): null
    {
        return null;
    }
}
