<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

/**
 * Extension contract for drivers with intercalation (leap days, months or
 * years). No bundled driver implements it yet.
 */
interface LeapRuleAware extends CalendarDriver
{
    /** Whether the period of $unit numbered $number is intercalary/leap. */
    public function isLeap(CalendarDefinition $definition, string $unit, int $number): bool;
}
