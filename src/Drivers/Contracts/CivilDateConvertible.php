<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Dates\CalendarDateResult;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

interface CivilDateConvertible extends CalendarDriver
{
    public function fromCivil(CalendarDefinition $definition, CivilDate $date): CalendarDateResult;
}
