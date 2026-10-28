<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

/**
 * Extension contract for adding calendar units (months, years) where their
 * length depends on the calendar. No bundled driver implements it yet:
 * civil-day arithmetic is already exact through CivilDate::addDays().
 */
interface DateArithmeticCapable extends CalendarDriver
{
    public function add(CalendarDefinition $definition, CalendarDate $date, int $amount, string $unit): CalendarDate;
}
