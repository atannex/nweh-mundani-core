<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tenancy;

use InvalidArgumentException;

/**
 * Tenant keys are strings; '' is the global scope shared by every tenant.
 * Using a non-null sentinel keeps unique indexes portable (NULLs are never
 * equal in SQL, so they would let duplicates through).
 */
final class TenantKey
{
    public const string GLOBAL = '';

    public static function normalise(string|int|null $tenant): string
    {
        $key = $tenant === null ? self::GLOBAL : (string) $tenant;

        if ($key !== self::GLOBAL && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $key)) {
            throw new InvalidArgumentException("[{$key}] is not a valid tenant key.");
        }

        return $key;
    }
}
