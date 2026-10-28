<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;
use Atannex\NwehMundani\Core\Rules\Specifications\Recurrence;
use InvalidArgumentException;

/**
 * "recurrence": every `interval` days, months or years from `start`, optionally
 * limited to `count` occurrences. Monthly and yearly recurrences keep the
 * start's day (and month); periods without that day are skipped, not shifted.
 */
final class RecurrenceHandler implements RuleHandler
{
    public function type(): string
    {
        return 'recurrence';
    }

    public function parse(array $parameters): RuleSpecification|array
    {
        $p = new Parameters($parameters);
        $p->onlyKeys(['frequency', 'interval', 'start', 'count']);
        $frequency = $p->choice('frequency', Recurrence::FREQUENCIES);
        $interval = $p->int('interval', 1, 100000, required: false, default: 1);
        $start = $p->date('start');
        $count = $p->int('count', 1, 1000000, required: false);

        return $p->ok() && $frequency !== null && $interval !== null && $start !== null
            ? new Recurrence($frequency, $interval, $start, $count)
            : $p->violations;
    }

    public function matches(RuleSpecification $specification, RuleContext $context): bool
    {
        if (! $specification instanceof Recurrence) {
            throw new InvalidArgumentException('Expected a Recurrence specification.');
        }

        $date = $context->date;
        $start = $specification->start;

        if ($date->isBefore($start)) {
            return false;
        }

        $steps = match ($specification->frequency) {
            'daily' => $start->daysUntil($date),
            'monthly' => $date->day === $start->day ? ($date->year - $start->year) * 12 + $date->month - $start->month : null,
            'yearly' => $date->day === $start->day && $date->month === $start->month ? $date->year - $start->year : null,
            default => null,
        };

        if ($steps === null || $steps % $specification->interval !== 0) {
            return false;
        }

        if ($specification->count === null) {
            return true;
        }

        // Skipped periods (no 31st, no 29 February) do not consume the count.
        return $this->ordinal($specification, intdiv($steps, $specification->interval)) < $specification->count;
    }

    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array
    {
        return [];
    }

    /** How many real occurrences precede step $n (0-based). */
    private function ordinal(Recurrence $rule, int $n): int
    {
        if ($rule->frequency === 'daily') {
            return $n;
        }

        $taken = 0;

        for ($i = 0; $i < $n && $taken < ($rule->count ?? PHP_INT_MAX); $i++) {
            $months = $rule->frequency === 'monthly' ? $i * $rule->interval : $i * $rule->interval * 12;
            $total = $rule->start->year * 12 + ($rule->start->month - 1) + $months;
            $year = intdiv($total, 12);
            $month = $total % 12 + 1;

            if ($rule->start->day <= CivilDate::daysInMonthOf($year, $month)) {
                $taken++;
            }
        }

        return $taken;
    }
}
