<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

/** The calendar exists but is suspended or archived. */
final class CalendarUnavailable extends NwehMundaniException
{
    public static function because(string $calendar, string $status): self
    {
        return new self("Calendar [{$calendar}] is {$status} and does not resolve. Its history stays available through the query service.");
    }
}
