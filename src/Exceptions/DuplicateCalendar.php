<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class DuplicateCalendar extends NwehMundaniException
{
    public static function conflicting(string $identifier, string $version): self
    {
        return new self("Calendar [{$identifier}] version [{$version}] is already registered with different content. Register a new version instead of replacing it.");
    }
}
