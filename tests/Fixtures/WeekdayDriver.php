<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests\Fixtures;

use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CalendarDateResult;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Contracts\CivilDateConvertible;
use Atannex\NwehMundani\Core\Drivers\DriverKind;

/** A third-party driver used only by tests: exposes the ISO weekday as a component. */
final class WeekdayDriver implements CivilDateConvertible
{
    public function key(): string
    {
        return 'iso-weekday';
    }

    public function kind(): DriverKind
    {
        return DriverKind::Custom;
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function fromCivil(CalendarDefinition $definition, CivilDate $date): CalendarDateResult
    {
        return new CalendarDateResult($definition->id(), $date, ConversionStatus::Resolved, new CalendarDate($definition->id(), $definition->version->number, $date, components: ['weekday' => $date->isoWeekday()]));
    }
}
