<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Kernel\CalendarContext;

/**
 * Supplies the observances that may apply to a calendar. The service
 * evaluates their rules and visibility; resolvers only find candidates.
 */
interface ObservanceResolver
{
    /** @return iterable<Observance> */
    public function candidates(CalendarContext $context, string $calendar): iterable;
}
