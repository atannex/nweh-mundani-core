<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class DateOutOfRange extends NwehMundaniException
{
    public static function forCalendar(string $calendar, string $date, string $reason): self
    {
        return new self("{$date} is outside calendar [{$calendar}]: {$reason}");
    }
}
