<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class ObservanceNotFound extends NwehMundaniException
{
    public static function forIdentifier(string $calendar, string $id): self
    {
        return new self("Observance [{$id}] of calendar [{$calendar}] does not exist in this scope.");
    }
}
