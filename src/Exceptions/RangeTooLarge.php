<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

/** A range query exceeded nweh-mundani.queries.max_range_days (protects against runaway requests). */
final class RangeTooLarge extends NwehMundaniException
{
    public static function days(int $requested, int $limit): self
    {
        return new self("A range of {$requested} days exceeds the limit of {$limit} (nweh-mundani.queries.max_range_days).");
    }

    public static function because(string $operation, int $requested, int $limit): self
    {
        return new self("{$operation} was asked for {$requested} calendars; the limit is {$limit} (nweh-mundani.queries.max_calendars).");
    }
}
