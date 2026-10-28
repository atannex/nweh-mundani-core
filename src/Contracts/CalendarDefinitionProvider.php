<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Contracts;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Observances\Observance;

/**
 * Lets another Composer package (or the application) contribute calendars,
 * observances and sources. Tag the class "nweh-mundani.providers" in the
 * container or list it in config('nweh-mundani.providers'). It is read
 * lazily, on the first calendar lookup.
 */
interface CalendarDefinitionProvider
{
    /** @return iterable<CalendarDefinition|array<string, mixed>> */
    public function calendars(): iterable;

    /** @return iterable<Observance|array<string, mixed>> */
    public function observances(): iterable;

    /** @return iterable<Source|array<string, mixed>> */
    public function sources(): iterable;
}
