<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class CalendarNotFound extends NwehMundaniException
{
    public static function forIdentifier(string $identifier, string $tenantKey = '', ?string $version = null): self
    {
        $scope = $tenantKey === '' ? '' : " for tenant [{$tenantKey}]";
        $what = $version === null ? "[{$identifier}]" : "[{$identifier}] version [{$version}]";

        return new self("Calendar {$what} is not registered{$scope}.");
    }
}
