<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules;

use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Closure;

/**
 * What a rule may look at when deciding whether it matches a date.
 */
final readonly class RuleContext
{
    /**
     * @param  Closure(CivilDate): ?CalendarDate  $resolve  Calendar representation of any other date.
     * @param  Closure(string, string, CivilDate, bool): ?CivilDate  $next  Next occurrence of (cycle, position) from a date.
     * @param  Closure(string, string, CivilDate, bool): ?CivilDate  $previous  Previous occurrence of (cycle, position) from a date.
     */
    public function __construct(
        public CalendarDefinition $definition,
        public CivilDate $date,
        public ?CalendarDate $calendarDate,
        public Closure $resolve,
        public Closure $next,
        public Closure $previous,
    ) {}
}
