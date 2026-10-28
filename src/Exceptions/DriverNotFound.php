<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class DriverNotFound extends NwehMundaniException
{
    public static function forKey(string $key): self
    {
        return new self("No calendar driver is registered under [{$key}].");
    }
}
