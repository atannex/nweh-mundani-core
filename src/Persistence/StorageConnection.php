<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence;

use Atannex\NwehMundani\Core\Tenancy\SpatieMultitenancy;

/**
 * The database connection the nmc_* tables live on.
 *
 * nweh-mundani.persistence.connection wins when set. Otherwise, when Spatie
 * gives each tenant its own database, the tables go on the landlord
 * connection, so global calendars stay shared and tenant_key keeps tenants
 * apart. Null means the default connection.
 */
final class StorageConnection
{
    public static function name(): ?string
    {
        $configured = config('nweh-mundani.persistence.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (SpatieMultitenancy::databasePerTenant()) {
            return SpatieMultitenancy::landlordConnection();
        }

        return null;
    }
}
