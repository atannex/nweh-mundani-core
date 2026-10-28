<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * A civil month in one calendar. $firstIsoWeekday (1 = Monday) lets a grid
 * be laid out without date maths; $previous/$next are the neighbouring
 * months as {year, month}, or null at the edges of the supported range.
 */
final readonly class CalendarMonthData extends Data
{
    /**
     * @param  list<CalendarDayData>  $days
     * @param  array{year: int, month: int}|null  $previous
     * @param  array{year: int, month: int}|null  $next
     */
    public function __construct(
        public CalendarSummaryData $calendar,
        public int $year,
        public int $month,
        public string $start,
        public string $end,
        public int $firstIsoWeekday,
        public array $days,
        public ?array $previous,
        public ?array $next,
    ) {}

    public function day(int $dayOfMonth): ?CalendarDayData
    {
        return $this->days[$dayOfMonth - 1] ?? null;
    }

    public function toArray(bool $withCalendar = true): array
    {
        return [
            ...($withCalendar ? ['calendar' => $this->calendar->toArray()] : []),
            'year' => $this->year,
            'month' => $this->month,
            'start' => $this->start,
            'end' => $this->end,
            'days_in_month' => count($this->days),
            'first_iso_weekday' => $this->firstIsoWeekday,
            'days' => self::all($this->days),
            'navigation' => ['previous' => $this->previous, 'next' => $this->next],
        ];
    }
}
