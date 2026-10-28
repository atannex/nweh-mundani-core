<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

/**
 * A span of civil dates a calendar treats as one unit: for the cyclic
 * driver, one complete run of a cycle.
 */
final readonly class CalendarPeriod
{
    public function __construct(
        public string $calendar,
        public string $unit,
        public int $sequence,
        public CalendarDateRange $range,
    ) {}

    /** @return array{calendar: string, unit: string, sequence: int, start: string, end: string} */
    public function toArray(): array
    {
        return [
            'calendar' => $this->calendar,
            'unit' => $this->unit,
            'sequence' => $this->sequence,
            'start' => $this->range->start->toString(),
            'end' => $this->range->end->toString(),
        ];
    }
}
