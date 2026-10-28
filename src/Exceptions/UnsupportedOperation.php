<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class UnsupportedOperation extends NwehMundaniException
{
    public static function forCalendar(string $calendar, string $operation, string $reason): self
    {
        return new self("Calendar [{$calendar}] does not support {$operation}: {$reason}");
    }
}
