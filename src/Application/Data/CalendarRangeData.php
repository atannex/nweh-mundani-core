<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/** An arbitrary inclusive run of days in one calendar. */
final readonly class CalendarRangeData extends Data
{
    /** @param list<CalendarDayData> $days */
    public function __construct(
        public CalendarSummaryData $calendar,
        public string $start,
        public string $end,
        public array $days,
    ) {}

    public function toArray(): array
    {
        return ['calendar' => $this->calendar->toArray(), 'start' => $this->start, 'end' => $this->end, 'days' => self::all($this->days)];
    }
}
