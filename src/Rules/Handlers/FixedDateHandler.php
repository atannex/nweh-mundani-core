<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;
use Atannex\NwehMundani\Core\Rules\Specifications\FixedDate;
use InvalidArgumentException;

/**
 * "fixed-date": one civil date ({date}), or the same Gregorian month and day
 * every year ({month, day}). 29 February matches only in leap years.
 */
final class FixedDateHandler implements RuleHandler
{
    public function type(): string
    {
        return 'fixed-date';
    }

    public function parse(array $parameters): RuleSpecification|array
    {
        $p = new Parameters($parameters);
        $p->onlyKeys(['date', 'month', 'day']);

        if ($p->has('date')) {
            if ($p->has('month') || $p->has('day')) {
                $p->fail('date', 'give either a date or a month and day, not both.');
            }

            $date = $p->date('date');

            return $p->ok() && $date !== null ? new FixedDate(date: $date) : $p->violations;
        }

        $month = $p->int('month', 1, 12);
        $day = $p->int('day', 1, 31);

        if ($month !== null && $day !== null && $day > CivilDate::daysInMonthOf(2000, $month)) {
            $p->fail('day', "month {$month} has no day {$day}.");
        }

        return $p->ok() && $month !== null && $day !== null ? new FixedDate(month: $month, day: $day) : $p->violations;
    }

    public function matches(RuleSpecification $specification, RuleContext $context): bool
    {
        if (! $specification instanceof FixedDate) {
            throw new InvalidArgumentException('Expected a FixedDate specification.');
        }

        return $specification->date !== null
            ? $specification->date->equals($context->date)
            : $context->date->month === $specification->month && $context->date->day === $specification->day;
    }

    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array
    {
        return [];
    }
}
