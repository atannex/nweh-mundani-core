<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;

/**
 * A civil date as one calendar represents it.
 *
 * Only the parts the calendar actually has are present: a purely cyclic
 * calendar carries cycle readings and no year, month or day components.
 */
final readonly class CalendarDate
{
    /**
     * @param  array<string, CycleReading>  $cycles  Keyed by cycle identifier, in definition order.
     * @param  array<string, int>  $components  Numbered components (year, month, day…) for drivers that have them.
     * @param  array<string, string>  $identifiers  Technical keys of named components (e.g. 'month' => 'adar-ii'); labels come from the definition's names.
     */
    public function __construct(
        public string $calendar,
        public string $version,
        public CivilDate $civil,
        public array $cycles = [],
        public array $components = [],
        public array $identifiers = [],
    ) {}

    public function cycle(string $id): CycleReading
    {
        return $this->cycles[$id] ?? throw UnsupportedOperation::forCalendar($this->calendar, "cycle [{$id}]", 'no cycle with that identifier is in force on '.$this->civil.'.');
    }

    public function hasCycle(string $id): bool
    {
        return isset($this->cycles[$id]);
    }

    /** The first cycle in definition order, which is what label() reads. */
    public function primary(): ?CycleReading
    {
        return $this->cycles[array_key_first($this->cycles) ?? ''] ?? null;
    }

    public function label(?string $locale = null): ?string
    {
        return $this->primary()?->label($locale);
    }

    public function component(string $name): ?int
    {
        return $this->components[$name] ?? null;
    }

    /** The weakest certainty among the cycle readings. */
    public function certainty(): ?Certainty
    {
        $rank = [Certainty::Assumed->value => 0, Certainty::Derived->value => 1, Certainty::Recorded->value => 2];
        $weakest = null;

        foreach ($this->cycles as $reading) {
            if ($weakest === null || $rank[$reading->certainty->value] < $rank[$weakest->value]) {
                $weakest = $reading->certainty;
            }
        }

        return $weakest;
    }

    /** @return array<string, mixed> */
    public function toArray(?string $locale = null): array
    {
        return [
            'calendar' => $this->calendar,
            'version' => $this->version,
            'civil' => $this->civil->toString(),
            'cycles' => array_map(static fn (CycleReading $r): array => $r->toArray($locale), $this->cycles),
            'components' => $this->components,
            'identifiers' => $this->identifiers,
        ];
    }
}
