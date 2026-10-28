<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class ImmutableVersion extends NwehMundaniException
{
    public static function forVersion(string $calendar, string $version, string $status): self
    {
        return new self("Calendar [{$calendar}] version [{$version}] is {$status} and cannot be changed. Create a new version.");
    }
}
