<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CalendarPeriod;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

interface PeriodEnumerable extends CalendarDriver
{
    /** The period of the given unit (a cycle identifier for cyclic drivers) containing a date. */
    public function periodContaining(CalendarDefinition $definition, string $unit, CivilDate $date): CalendarPeriod;

    /** @return list<CalendarPeriod> Periods overlapping a range, in order. */
    public function periodsBetween(CalendarDefinition $definition, string $unit, CalendarDateRange $range): array;
}
