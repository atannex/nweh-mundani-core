<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/** A civil year in one calendar, as twelve months. */
final readonly class CalendarYearData extends Data
{
    /** @param list<CalendarMonthData> $months */
    public function __construct(
        public CalendarSummaryData $calendar,
        public int $year,
        public bool $isLeapYear,
        public array $months,
        public ?int $previous,
        public ?int $next,
    ) {}

    public function month(int $month): ?CalendarMonthData
    {
        return $this->months[$month - 1] ?? null;
    }

    public function toArray(): array
    {
        return [
            'calendar' => $this->calendar->toArray(),
            'year' => $this->year,
            'is_leap_year' => $this->isLeapYear,
            'start' => sprintf('%04d-01-01', $this->year),
            'end' => sprintf('%04d-12-31', $this->year),
            'months' => array_map(static fn (CalendarMonthData $m): array => $m->toArray(withCalendar: false), $this->months),
            'navigation' => ['previous' => $this->previous, 'next' => $this->next],
        ];
    }
}
