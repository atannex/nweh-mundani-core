<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarValidationCapable;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;

/**
 * Semantic checks on a structurally valid definition: the driver exists and
 * accepts it, and any unverified assumptions are flagged as warnings.
 * Mathematical validity and historical verification are reported separately.
 */
final readonly class CalendarValidator
{
    public function __construct(private DriverRegistry $drivers) {}

    public function validate(CalendarDefinition $definition): ValidationReport
    {
        $report = new ValidationReport($definition->id());

        if (! $this->drivers->has($definition->driver)) {
            return new ValidationReport($definition->id(), [new Violation('driver', "no driver is registered under [{$definition->driver}].")]);
        }

        $driver = $this->drivers->get($definition->driver);

        if ($driver instanceof CalendarValidationCapable) {
            $report = $report->merge($driver->validate($definition));
        }

        $warnings = [];

        foreach ($definition->metadata->unverifiedAssumptions() as $assumption) {
            $warnings[] = new Violation("metadata.assumptions.{$assumption->id}", "{$assumption->statement} (status: {$assumption->status->value})");
        }

        return $report->merge(new ValidationReport($definition->id(), warnings: $warnings));
    }
}
