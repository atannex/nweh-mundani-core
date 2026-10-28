<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\ValidationReport;

/** Checks a driver needs beyond the generic structural validation. */
interface CalendarValidationCapable extends CalendarDriver
{
    public function validate(CalendarDefinition $definition): ValidationReport;
}
