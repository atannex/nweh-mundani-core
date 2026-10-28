<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * An inclusive, closed run of civil dates.
 *
 * @implements IteratorAggregate<int, CivilDate>
 */
final readonly class CalendarDateRange implements Countable, IteratorAggregate
{
    public function __construct(
        public CivilDate $start,
        public CivilDate $end,
    ) {
        if ($end->isBefore($start)) {
            throw new InvalidArgumentException("Range end {$end} is before its start {$start}.");
        }
    }

    public static function between(CivilDate|string $start, CivilDate|string $end): self
    {
        return new self(CivilDate::from($start), CivilDate::from($end));
    }

    public function contains(CivilDate $date): bool
    {
        return ! $date->isBefore($this->start) && ! $date->isAfter($this->end);
    }

    public function count(): int
    {
        // The constructor guarantees end >= start, so this is at least 1.
        return max(1, $this->start->daysUntil($this->end) + 1);
    }

    /** @return Generator<int, CivilDate> */
    public function getIterator(): Generator
    {
        for ($n = $this->start->dayNumber; $n <= $this->end->dayNumber; $n++) {
            yield CivilDate::fromDayNumber($n);
        }
    }

    public function __toString(): string
    {
        return "{$this->start}..{$this->end}";
    }
}
