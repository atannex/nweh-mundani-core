<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class UnsafeRegistration extends NwehMundaniException
{
    public static function because(string $reason): self
    {
        return new self("Refused registration: {$reason}");
    }
}
