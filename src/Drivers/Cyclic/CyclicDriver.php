<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Cyclic;

use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CalendarDateResult;
use Atannex\NwehMundani\Core\Dates\CalendarPeriod;
use Atannex\NwehMundani\Core\Dates\Certainty;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Dates\CycleReading;
use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CycleContinuity;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarValidationCapable;
use Atannex\NwehMundani\Core\Drivers\Contracts\CivilDateConvertible;
use Atannex\NwehMundani\Core\Drivers\Contracts\CycleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\PeriodEnumerable;
use Atannex\NwehMundani\Core\Drivers\DriverKind;
use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;
use Atannex\NwehMundani\Core\Kernel\ValidationReport;
use Atannex\NwehMundani\Core\Kernel\Violation;
use WeakMap;

/**
 * Calendars made of repeating cycles of any positive length.
 *
 * For a date d, an anchor (date a, position p) and a cycle of length n:
 *
 *   offset = (d − a) + (ordinal(p) − 1)      signed whole days
 *   index  = ((offset mod n) + n) mod n       0-based position
 *
 * (d − a) is a difference of integer day numbers, so leap years, month and
 * year boundaries and daylight-saving changes cannot disturb it. An explicit
 * override for d wins over the anchor; anchors only apply inside their
 * effective ranges, and anchors covering the same day must agree.
 *
 * A cycle position names infinitely many days, so this driver does not
 * implement reverse (calendar → civil) conversion; use the cycle queries.
 */
final class CyclicDriver implements CalendarValidationCapable, CivilDateConvertible, CycleAware, PeriodEnumerable
{
    public const string KEY = 'cyclic';

    /** @var WeakMap<CalendarCycle, list<int>> */
    private WeakMap $breakpoints;

    public function __construct()
    {
        $this->breakpoints = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function kind(): DriverKind
    {
        return DriverKind::Cyclic;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function fromCivil(CalendarDefinition $definition, CivilDate $date): CalendarDateResult
    {
        if (! $definition->effective->contains($date)) {
            return new CalendarDateResult($definition->id(), $date, ConversionStatus::OutOfRange, notes: ["The calendar is in force {$definition->effective}."]);
        }

        $readings = [];
        $notes = [];

        foreach ($definition->cycles as $cycle) {
            $reading = $this->read($definition, $cycle, $date);

            if ($reading === null) {
                $notes[] = "Cycle [{$cycle->id}] has no anchor or override in force on {$date}.";

                continue;
            }

            $readings[$cycle->id] = $reading;
        }

        $status = match (true) {
            $readings === [] => ConversionStatus::OutOfRange,
            count($readings) < count($definition->cycles) => ConversionStatus::Partial,
            default => ConversionStatus::Resolved,
        };

        $calendarDate = $readings === [] ? null : new CalendarDate($definition->id(), $definition->version->number, $date, $readings);

        return new CalendarDateResult($definition->id(), $date, $status, $calendarDate, notes: $notes);
    }

    /**
     * One cycle's reading on a date, or null when nothing governs it.
     */
    public function read(CalendarDefinition $definition, CalendarCycle $cycle, CivilDate $date): ?CycleReading
    {
        if (! $cycle->effective->contains($date) || ! $definition->effective->contains($date)) {
            return null;
        }

        $anchor = $cycle->anchorFor($date);
        $exception = $cycle->exceptionOn($date);

        if ($exception !== null) {
            $position = $cycle->position($exception->positionId)
                ?? throw UnsupportedOperation::forCalendar($definition->id(), 'reading', "override [{$exception->id}] names unknown position [{$exception->positionId}].");

            $sequence = $anchor === null ? 0 : $this->sequence($definition, $cycle, $cycle->offsetFrom($anchor, $date));

            return new CycleReading($cycle->id, $position, $position->ordinal - 1, $cycle->length(), $sequence, Certainty::Recorded, $anchor?->id, $exception->id);
        }

        if ($anchor === null) {
            return null;
        }

        $offset = $cycle->offsetFrom($anchor, $date);
        $index = CalendarCycle::mod($offset, $cycle->length());

        $certainty = match (true) {
            $anchor->date->equals($date) => Certainty::Recorded,
            $cycle->continuity === CycleContinuity::Verified => Certainty::Derived,
            default => Certainty::Assumed,
        };

        return new CycleReading($cycle->id, $cycle->positionAt($index), $index, $cycle->length(), $this->sequence($definition, $cycle, $offset), $certainty, $anchor->id);
    }

    public function nextOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate
    {
        $subject = $this->cycleOf($definition, $cycle);
        $target = $this->targetIndex($definition, $subject, $position);
        $length = $subject->length();
        $breaks = $this->breakpointsOf($definition, $subject);
        $cursor = $inclusive ? $from->dayNumber : $from->dayNumber + 1;

        // Between consecutive breakpoints the governing anchor (or override)
        // is constant, so the next hit is found arithmetically per segment.
        while (true) {
            $nextBreak = $this->firstAfter($breaks, $cursor);
            $index = $this->indexAt($definition, $subject, $cursor);

            if ($index !== null) {
                $candidate = $cursor + CalendarCycle::mod($target - $index, $length);

                if ($nextBreak === null || $candidate < $nextBreak) {
                    return CivilDate::fromDayNumber($candidate);
                }
            }

            if ($nextBreak === null) {
                return null;
            }

            $cursor = $nextBreak;
        }
    }

    public function previousOccurrence(CalendarDefinition $definition, string $cycle, string $position, CivilDate $from, bool $inclusive = true): ?CivilDate
    {
        $subject = $this->cycleOf($definition, $cycle);
        $target = $this->targetIndex($definition, $subject, $position);
        $length = $subject->length();
        $breaks = $this->breakpointsOf($definition, $subject);
        $cursor = $inclusive ? $from->dayNumber : $from->dayNumber - 1;

        while (true) {
            $segmentStart = $this->lastAtOrBefore($breaks, $cursor);
            $index = $this->indexAt($definition, $subject, $cursor);

            if ($index !== null) {
                $candidate = $cursor - CalendarCycle::mod($index - $target, $length);

                if ($segmentStart === null || $candidate >= $segmentStart) {
                    return CivilDate::fromDayNumber($candidate);
                }
            }

            if ($segmentStart === null) {
                return null;
            }

            $cursor = $segmentStart - 1;
        }
    }

    public function occurrencesBetween(CalendarDefinition $definition, string $cycle, string $position, CalendarDateRange $range): array
    {
        $found = [];
        $next = $this->nextOccurrence($definition, $cycle, $position, $range->start);

        while ($next !== null && $range->contains($next)) {
            $found[] = $next;
            $next = $this->nextOccurrence($definition, $cycle, $position, $next, inclusive: false);
        }

        return $found;
    }

    public function periodContaining(CalendarDefinition $definition, string $unit, CivilDate $date): CalendarPeriod
    {
        $cycle = $this->cycleOf($definition, $unit);

        $anchor = ($cycle->effective->contains($date) && $definition->effective->contains($date)) ? $cycle->anchorFor($date) : null;

        if ($anchor === null) {
            throw DateOutOfRange::forCalendar($definition->id(), $date->toString(), "no anchor of cycle [{$cycle->id}] is in force.");
        }

        $offset = $cycle->offsetFrom($anchor, $date);
        $start = $date->addDays(-CalendarCycle::mod($offset, $cycle->length()));

        return new CalendarPeriod($definition->id(), $cycle->id, $this->sequence($definition, $cycle, $offset), new CalendarDateRange($start, $start->addDays($cycle->length() - 1)));
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

    public function validate(CalendarDefinition $definition): ValidationReport
    {
        $errors = [];
        $warnings = [];

        if ($definition->cycles === []) {
            $errors[] = new Violation('cycles', 'A cyclic calendar needs at least one cycle.');
        }

        foreach ($definition->cycles as $cycle) {
            $path = "cycles.{$cycle->id}";

            foreach ($cycle->anchors as $anchor) {
                if ($cycle->position($anchor->positionId) === null) {
                    $errors[] = new Violation("{$path}.anchors.{$anchor->id}", "names unknown position [{$anchor->positionId}].");
                }

                if (! $anchor->effective->contains($anchor->date)) {
                    $warnings[] = new Violation("{$path}.anchors.{$anchor->id}", "its own date {$anchor->date} is outside its effective range {$anchor->effective}.");
                }
            }

            foreach ($cycle->exceptions as $exception) {
                if ($cycle->position($exception->positionId) === null) {
                    $errors[] = new Violation("{$path}.exceptions.{$exception->id}", "names unknown position [{$exception->positionId}].");
                }
            }

            if (array_filter($errors, static fn (Violation $v): bool => str_starts_with($v->path, $path)) !== []) {
                continue;
            }

            $anchors = $cycle->anchors;

            foreach ($anchors as $i => $a) {
                foreach (array_slice($anchors, $i + 1) as $b) {
                    if ($a->id === $b->id) {
                        $errors[] = new Violation("{$path}.anchors", "anchor identifier [{$a->id}] is used twice.");
                    } elseif ($a->effective->overlaps($b->effective) && ! $cycle->anchorsAgree($a, $b)) {
                        $errors[] = new Violation("{$path}.anchors", "anchors [{$a->id}] and [{$b->id}] overlap ({$a->effective} / {$b->effective}) but disagree about the position. Give them non-overlapping effective ranges.");
                    }
                }
            }

            if ($cycle->continuity === CycleContinuity::Assumed) {
                $warnings[] = new Violation($path, 'continuous repetition is assumed, not verified: results away from anchor dates are labelled "assumed".');
            }
        }

        return new ValidationReport($definition->id(), $errors, $warnings);
    }

    private function sequence(CalendarDefinition $definition, CalendarCycle $cycle, int $offset): int
    {
        $run = CalendarCycle::floorDiv($offset, $cycle->length());

        if ($definition->epoch === null) {
            return $run;
        }

        $anchor = $cycle->anchorFor($definition->epoch->date) ?? $cycle->anchors[0];

        return $run - CalendarCycle::floorDiv($cycle->offsetFrom($anchor, $definition->epoch->date), $cycle->length());
    }

    private function indexAt(CalendarDefinition $definition, CalendarCycle $cycle, int $dayNumber): ?int
    {
        return $this->read($definition, $cycle, CivilDate::fromDayNumber($dayNumber))?->index;
    }

    private function cycleOf(CalendarDefinition $definition, string $id): CalendarCycle
    {
        return $definition->cycle($id) ?? throw UnsupportedOperation::forCalendar($definition->id(), "cycle [{$id}]", 'the calendar defines no such cycle.');
    }

    private function targetIndex(CalendarDefinition $definition, CalendarCycle $cycle, string $position): int
    {
        $found = $cycle->findPosition($position)
            ?? throw UnsupportedOperation::forCalendar($definition->id(), "position [{$position}]", "cycle [{$cycle->id}] has no such position.");

        return $found->ordinal - 1;
    }

    /**
     * Day numbers where what governs the cycle can change: range bounds,
     * anchor bounds and the day of and after each override. Sorted, unique.
     *
     * @return list<int>
     */
    private function breakpointsOf(CalendarDefinition $definition, CalendarCycle $cycle): array
    {
        if (isset($this->breakpoints[$cycle])) {
            return $this->breakpoints[$cycle];
        }

        $points = [];

        foreach ([$definition->effective, $cycle->effective, ...array_map(static fn ($a) => $a->effective, $cycle->anchors)] as $range) {
            if ($range->from !== null) {
                $points[] = $range->from->dayNumber;
            }

            if ($range->until !== null) {
                $points[] = $range->until->dayNumber + 1;
            }
        }

        foreach ($cycle->exceptions as $exception) {
            $points[] = $exception->date->dayNumber;
            $points[] = $exception->date->dayNumber + 1;
        }

        $points = array_values(array_unique($points));
        sort($points);

        return $this->breakpoints[$cycle] = $points;
    }

    /** @param list<int> $sorted */
    private function firstAfter(array $sorted, int $value): ?int
    {
        foreach ($sorted as $point) {
            if ($point > $value) {
                return $point;
            }
        }

        return null;
    }

    /** @param list<int> $sorted */
    private function lastAtOrBefore(array $sorted, int $value): ?int
    {
        $found = null;

        foreach ($sorted as $point) {
            if ($point > $value) {
                break;
            }

            $found = $point;
        }

        return $found;
    }
}
