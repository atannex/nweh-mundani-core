<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * One civil day in one calendar: every cycle reading (labels localised,
 * originals exact), the observances occurring that day, and how certain the
 * values are. $status is resolved | partial | out-of-range | unsupported.
 */
final readonly class CalendarDayData extends Data
{
    /**
     * @param  list<DayCycleData>  $cycles
     * @param  list<OccurrenceData>  $occurrences
     * @param  array<string, int>  $components
     * @param  list<string>  $notes
     * @param  list<array{unit: string, id: string, number: int|null, label: string, original: string|null}>  $units  named components (e.g. the month), labels localised
     */
    public function __construct(
        public string $calendar,
        public string $version,
        public string $date,
        public int $isoWeekday,
        public string $status,
        public ?string $certainty,
        public array $cycles,
        public array $occurrences,
        public array $components = [],
        public array $notes = [],
        public array $units = [],
    ) {}

    /** The localised name of a named component, e.g. unit('month'). */
    public function unit(string $unit): ?string
    {
        foreach ($this->units as $entry) {
            if ($entry['unit'] === $unit) {
                return $entry['label'];
            }
        }

        return null;
    }

    public function cycle(string $id): ?DayCycleData
    {
        foreach ($this->cycles as $cycle) {
            if ($cycle->cycle === $id) {
                return $cycle;
            }
        }

        return null;
    }

    /** The first cycle's label: what most calendar grids show. */
    public function label(): ?string
    {
        return $this->cycles[0]->label ?? null;
    }

    /** @return list<string> Override identifiers that set a position on this day. */
    public function exceptions(): array
    {
        return array_values(array_filter(array_map(static fn (DayCycleData $c): ?string => $c->exception, $this->cycles)));
    }

    public function toArray(): array
    {
        return [
            'calendar' => $this->calendar,
            'version' => $this->version,
            'date' => $this->date,
            'iso_weekday' => $this->isoWeekday,
            'status' => $this->status,
            'certainty' => $this->certainty,
            'label' => $this->label(),
            'cycles' => self::all($this->cycles),
            'occurrences' => self::all($this->occurrences),
            'exceptions' => $this->exceptions(),
            'components' => $this->components,
            'units' => $this->units,
            'notes' => $this->notes,
        ];
    }
}
