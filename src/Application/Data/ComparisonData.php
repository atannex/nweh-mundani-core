<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * One civil day across several calendars. Each entry stays independently
 * identified by its calendar id; nothing is merged, even where day names coincide.
 */
final readonly class ComparisonData extends Data
{
    /** @param array<string, CalendarDayData> $calendars keyed by calendar id, in the order asked */
    public function __construct(
        public string $date,
        public array $calendars,
    ) {}

    public function get(string $calendar): ?CalendarDayData
    {
        return $this->calendars[$calendar] ?? null;
    }

    /** @return array<string, string|null> */
    public function labels(): array
    {
        return array_map(static fn (CalendarDayData $d): ?string => $d->label(), $this->calendars);
    }

    public function toArray(): array
    {
        return ['date' => $this->date, 'calendars' => array_map(static fn (CalendarDayData $d): array => $d->toArray(), $this->calendars)];
    }
}
