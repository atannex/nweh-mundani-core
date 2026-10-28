<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

/**
 * Calendar representation → civil date. Only drivers whose representation
 * identifies a single day (a year/month/day calendar) can implement this; a
 * recurring cycle position on its own names infinitely many days.
 */
interface ReverseDateConvertible extends CalendarDriver
{
    /**
     * @param  array<string, int|string>  $components
     * @return list<CivilDate> One date when unambiguous; several when the representation is ambiguous.
     */
    public function toCivil(CalendarDefinition $definition, array $components): array;
}
