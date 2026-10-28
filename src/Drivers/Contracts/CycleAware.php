<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

interface CycleAware extends CalendarDriver
{
    /**
     * The first date on or after (or strictly after) $from at which a cycle
     * stands at a position, or null if none exists within the cycle's range.
     */
    public function nextOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate;

    /** The last date on or before (or strictly before) $from at that position. */
    public function previousOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate;

    /** @return list<CivilDate> */
    public function occurrencesBetween(CalendarDefinition $definition, string $cycle, string $position, CalendarDateRange $range): array;
}
