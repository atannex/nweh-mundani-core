<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarValidationCapable;
use Atannex\NwehMundani\Core\Drivers\Contracts\CivilDateConvertible;
use Atannex\NwehMundani\Core\Drivers\Contracts\CycleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\DateArithmeticCapable;
use Atannex\NwehMundani\Core\Drivers\Contracts\LeapRuleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\PeriodEnumerable;
use Atannex\NwehMundani\Core\Drivers\Contracts\ReverseDateConvertible;

/**
 * What can be asked of a calendar. Derived from the interfaces its driver
 * actually implements, so it cannot claim an operation that would fail.
 */
final readonly class CalendarCapabilities
{
    /** @param list<Capability> $capabilities */
    public function __construct(public array $capabilities) {}

    /**
     * With a definition, capabilities that the definition cannot use are
     * dropped too (cycle queries on a calendar without cycles).
     */
    public static function ofDriver(CalendarDriver $driver, ?CalendarDefinition $definition = null): self
    {
        $map = [
            CivilDateConvertible::class => Capability::FromCivil,
            ReverseDateConvertible::class => Capability::ToCivil,
            PeriodEnumerable::class => Capability::Periods,
            CycleAware::class => Capability::CycleQueries,
            LeapRuleAware::class => Capability::LeapRules,
            DateArithmeticCapable::class => Capability::Arithmetic,
            CalendarValidationCapable::class => Capability::DriverValidation,
        ];

        $found = [];

        foreach ($map as $interface => $capability) {
            if ($driver instanceof $interface && ! ($capability === Capability::CycleQueries && $definition !== null && $definition->cycles === [])) {
                $found[] = $capability;
            }
        }

        return new self($found);
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return array_map(static fn (Capability $c): string => $c->value, $this->capabilities);
    }
}
