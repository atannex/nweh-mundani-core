<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Arithmetic;

use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CalendarDateResult;
use Atannex\NwehMundani\Core\Dates\CalendarPeriod;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarValidationCapable;
use Atannex\NwehMundani\Core\Drivers\Contracts\CivilDateConvertible;
use Atannex\NwehMundani\Core\Drivers\Contracts\CycleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\DateArithmeticCapable;
use Atannex\NwehMundani\Core\Drivers\Contracts\LeapRuleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\PeriodEnumerable;
use Atannex\NwehMundani\Core\Drivers\Contracts\ReverseDateConvertible;
use Atannex\NwehMundani\Core\Drivers\Cyclic\CyclicDriver;
use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;
use Atannex\NwehMundani\Core\Kernel\ValidationReport;
use Atannex\NwehMundani\Core\Kernel\Violation;
use InvalidArgumentException;

/**
 * Shared machinery for calendars defined by exact integer arithmetic on a
 * day count (year/month/day calendars such as Gregorian, Julian, tabular
 * Hijri, Hebrew). Subclasses supply only the calendar's own formulas;
 * everything is computed on "fixed" day numbers (R.D. 1 = Gregorian
 * 0001-01-01), with no floating point and no timestamps.
 *
 * A definition using one of these drivers may also declare cycles; they
 * run alongside the year/month/day reckoning (a composite calendar) and
 * are computed by the cyclic engine.
 *
 * Components on each CalendarDate: year, month, day, day_of_year,
 * days_in_month, months_in_year, is_leap_year (0/1). Identifier: month.
 */
abstract class ArithmeticCalendarDriver implements CalendarValidationCapable, CivilDateConvertible, CycleAware, DateArithmeticCapable, LeapRuleAware, PeriodEnumerable, ReverseDateConvertible
{
    /** R.D. of 1970-01-01, the zero of CivilDate::$dayNumber. */
    public const int UNIX_EPOCH_RD = 719163;

    private CyclicDriver $cycles;

    public function __construct()
    {
        $this->cycles = new CyclicDriver;
    }

    /** Fixed day (R.D.) of a date in this calendar. Inputs are already validated. */
    abstract protected function toFixed(CalendarDefinition $definition, int $year, int $month, int $day): int;

    /** @return array{int, int, int} year, month, day */
    abstract protected function fromFixed(CalendarDefinition $definition, int $fixed): array;

    abstract protected function daysInMonth(CalendarDefinition $definition, int $year, int $month): int;

    abstract protected function isLeapYear(CalendarDefinition $definition, int $year): bool;

    abstract protected function monthId(CalendarDefinition $definition, int $year, int $month): string;

    /** @return list<string> Every month identifier this calendar can produce. */
    abstract public function monthIds(): array;

    /** @return array<string, list<string|int|bool>> parameter => allowed values; the first is the default. */
    abstract public function parameterSpec(): array;

    /** Earliest supported fixed day, or null when the calendar is proleptic without limit. */
    protected function firstFixed(CalendarDefinition $definition): ?int
    {
        return null;
    }

    /** @return non-empty-list<int> The month numbers of a year, in calendar order. */
    protected function monthOrder(CalendarDefinition $definition, int $year): array
    {
        return range(1, $this->isLeapYear($definition, $year) ? $this->leapMonths() : $this->commonMonths());
    }

    protected function commonMonths(): int
    {
        return 12;
    }

    protected function leapMonths(): int
    {
        return $this->commonMonths();
    }

    /** The month that "the same month" means in another year (for adding years). */
    protected function equivalentMonth(CalendarDefinition $definition, int $fromYear, int $month, int $toYear): int
    {
        return min($month, max($this->monthOrder($definition, $toYear)));
    }

    public function fromCivil(CalendarDefinition $definition, CivilDate $date): CalendarDateResult
    {
        if (! $definition->effective->contains($date)) {
            return new CalendarDateResult($definition->id(), $date, ConversionStatus::OutOfRange, notes: ["The calendar is in force {$definition->effective}."]);
        }

        $fixed = $date->dayNumber + self::UNIX_EPOCH_RD;
        $first = $this->firstFixed($definition);

        if ($first !== null && $fixed < $first) {
            return new CalendarDateResult($definition->id(), $date, ConversionStatus::OutOfRange, notes: ["{$date} is before the calendar's epoch."]);
        }

        [$year, $month, $day] = $this->fromFixed($definition, $fixed);
        $readings = [];
        $notes = [];

        foreach ($definition->cycles as $cycle) {
            $reading = $this->cycles->read($definition, $cycle, $date);

            if ($reading === null) {
                $notes[] = "Cycle [{$cycle->id}] has no anchor or override in force on {$date}.";
            } else {
                $readings[$cycle->id] = $reading;
            }
        }

        $order = $this->monthOrder($definition, $year);

        $calendarDate = new CalendarDate(
            $definition->id(),
            $definition->version->number,
            $date,
            $readings,
            [
                'year' => $year,
                'month' => $month,
                'day' => $day,
                'day_of_year' => $fixed - $this->toFixed($definition, $year, $order[0], 1) + 1,
                'days_in_month' => $this->daysInMonth($definition, $year, $month),
                'months_in_year' => count($order),
                'is_leap_year' => $this->isLeapYear($definition, $year) ? 1 : 0,
            ],
            ['month' => $this->monthId($definition, $year, $month)],
        );

        return new CalendarDateResult($definition->id(), $date, $notes === [] ? ConversionStatus::Resolved : ConversionStatus::Partial, $calendarDate, notes: $notes);
    }

    /**
     * @param  array<string, int|string>  $components  year, month (number or identifier), day
     */
    public function toCivil(CalendarDefinition $definition, array $components): array
    {
        $year = $components['year'] ?? null;
        $day = $components['day'] ?? 1;
        $month = $components['month'] ?? null;

        if (! is_int($year) || ! is_int($day) || $month === null) {
            throw new InvalidArgumentException('Reverse conversion needs integer year and day and a month number or identifier.');
        }

        $number = is_int($month) ? $month : $this->monthNumber($definition, $year, $month);

        if ($number === null || ! in_array($number, $this->monthOrder($definition, $year), true) || $day < 1 || $day > $this->daysInMonth($definition, $year, $number)) {
            throw DateOutOfRange::forCalendar($definition->id(), "{$year}-{$month}-{$day}", 'there is no such day in this calendar.');
        }

        return [$this->civil($definition, $this->toFixed($definition, $year, $number, $day))];
    }

    public function periodContaining(CalendarDefinition $definition, string $unit, CivilDate $date): CalendarPeriod
    {
        if ($definition->cycle($unit) !== null) {
            return $this->cycles->periodContaining($definition, $unit, $date);
        }

        $calendarDate = $this->fromCivil($definition, $date)->date();
        $year = (int) $calendarDate->component('year');
        $month = (int) $calendarDate->component('month');

        return match ($unit) {
            'month' => $this->monthPeriod($definition, $year, $month),
            'year' => $this->yearPeriod($definition, $year),
            default => throw UnsupportedOperation::forCalendar($definition->id(), "periods of [{$unit}]", 'units are month, year, or a cycle identifier.'),
        };
    }

    public function periodsBetween(CalendarDefinition $definition, string $unit, CalendarDateRange $range): array
    {
        $periods = [];
        $cursor = $range->start;

        while (! $cursor->isAfter($range->end)) {
            try {
                $period = $this->periodContaining($definition, $unit, $cursor);
            } catch (DateOutOfRange) {
                $cursor = $cursor->addDays(1);

                continue;
            }

            $periods[] = $period;
            $cursor = $period->range->end->addDays(1);
        }

        return $periods;
    }

    /** The civil range of a month given in this calendar's own numbering. */
    public function monthPeriod(CalendarDefinition $definition, int $year, int $month): CalendarPeriod
    {
        if (! in_array($month, $this->monthOrder($definition, $year), true)) {
            throw DateOutOfRange::forCalendar($definition->id(), "{$year}-{$month}", 'there is no such month in this calendar.');
        }

        $start = $this->toFixed($definition, $year, $month, 1);

        return new CalendarPeriod($definition->id(), 'month', $month, new CalendarDateRange($this->civil($definition, $start), $this->civil($definition, $start + $this->daysInMonth($definition, $year, $month) - 1)));
    }

    public function yearPeriod(CalendarDefinition $definition, int $year): CalendarPeriod
    {
        $order = $this->monthOrder($definition, $year);
        $last = $order[count($order) - 1];

        return new CalendarPeriod($definition->id(), 'year', $year, new CalendarDateRange(
            $this->civil($definition, $this->toFixed($definition, $year, $order[0], 1)),
            $this->civil($definition, $this->toFixed($definition, $year, $last, $this->daysInMonth($definition, $year, $last))),
        ));
    }

    public function isLeap(CalendarDefinition $definition, string $unit, int $number): bool
    {
        return $unit === 'year'
            ? $this->isLeapYear($definition, $number)
            : throw UnsupportedOperation::forCalendar($definition->id(), "leap rules for [{$unit}]", 'only years are leap or common.');
    }

    /**
     * Add days, months or years. Adding months walks the calendar's own
     * month order; adding years keeps "the same" month (see
     * equivalentMonth()). The day is clamped to the target month's length.
     */
    public function add(CalendarDefinition $definition, CalendarDate $date, int $amount, string $unit): CalendarDate
    {
        if ($unit === 'day') {
            return $this->fromCivil($definition, $date->civil->addDays($amount))->date();
        }

        $year = $date->component('year') ?? throw UnsupportedOperation::forCalendar($definition->id(), 'arithmetic', 'the date has no year component.');
        $month = (int) $date->component('month');
        $day = (int) $date->component('day');

        if ($unit === 'year') {
            $month = $this->equivalentMonth($definition, $year, $month, $year + $amount);
            $year += $amount;
        } elseif ($unit === 'month') {
            $step = $amount <=> 0;

            for ($i = 0; $i < abs($amount); $i++) {
                $order = $this->monthOrder($definition, $year);
                $index = (int) array_search($month, $order, true) + $step;

                if ($index >= count($order)) {
                    $year++;
                    $month = $this->monthOrder($definition, $year)[0];
                } elseif ($index < 0) {
                    $year--;
                    $previous = $this->monthOrder($definition, $year);
                    $month = $previous[count($previous) - 1];
                } else {
                    $month = $order[$index];
                }
            }
        } else {
            throw UnsupportedOperation::forCalendar($definition->id(), "adding [{$unit}]", 'units are day, month and year.');
        }

        $day = min($day, $this->daysInMonth($definition, $year, $month));

        return $this->fromCivil($definition, $this->civil($definition, $this->toFixed($definition, $year, $month, $day)))->date();
    }

    public function nextOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate
    {
        return $this->cycles->nextOccurrence($definition, $cycle, $position, $from, $inclusive);
    }

    public function previousOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate
    {
        return $this->cycles->previousOccurrence($definition, $cycle, $position, $from, $inclusive);
    }

    public function occurrencesBetween(CalendarDefinition $definition, string $cycle, string $position, CalendarDateRange $range): array
    {
        return $this->cycles->occurrencesBetween($definition, $cycle, $position, $range);
    }

    public function validate(CalendarDefinition $definition): ValidationReport
    {
        $errors = [];

        foreach ($definition->parameters as $key => $value) {
            $spec = $this->parameterSpec()[$key] ?? null;

            if ($spec === null) {
                $errors[] = new Violation("parameters.{$key}", "is not a setting of the [{$this->key()}] driver; known: ".(implode(', ', array_keys($this->parameterSpec())) ?: 'none').'.');
            } elseif (! in_array($value, $spec, true)) {
                $errors[] = new Violation("parameters.{$key}", 'must be one of: '.implode(', ', array_map('strval', $spec)).'.');
            }
        }

        foreach ($definition->names as $unit => $names) {
            if ($unit !== 'month') {
                $errors[] = new Violation("names.{$unit}", "the [{$this->key()}] driver only names months.");

                continue;
            }

            foreach ($names as $name) {
                if (! in_array($name->id, $this->monthIds(), true)) {
                    $errors[] = new Violation("names.month.{$name->id}", 'is not a month of this calendar; known: '.implode(', ', $this->monthIds()).'.');
                }
            }
        }

        $report = new ValidationReport($definition->id(), $errors);

        return $definition->cycles === [] ? $report : $report->merge($this->cycles->validate($definition));
    }

    /** A driver setting, falling back to its documented default. */
    protected function setting(CalendarDefinition $definition, string $key): string|int|bool
    {
        $value = $definition->parameters[$key] ?? null;

        return is_string($value) || is_int($value) || is_bool($value) ? $value : $this->parameterSpec()[$key][0];
    }

    protected function monthNumber(CalendarDefinition $definition, int $year, string $id): ?int
    {
        foreach ($this->monthOrder($definition, $year) as $month) {
            if ($this->monthId($definition, $year, $month) === $id) {
                return $month;
            }
        }

        return null;
    }

    protected function civil(CalendarDefinition $definition, int $fixed): CivilDate
    {
        try {
            return CivilDate::fromDayNumber($fixed - self::UNIX_EPOCH_RD);
        } catch (InvalidArgumentException $e) {
            throw DateOutOfRange::forCalendar($definition->id(), "R.D. {$fixed}", $e->getMessage());
        }
    }

    /** Floor division (exact for negatives). */
    protected static function div(int $a, int $b): int
    {
        return CalendarCycle::floorDiv($a, $b);
    }

    protected static function mod(int $a, int $b): int
    {
        return CalendarCycle::mod($a, $b);
    }
}
