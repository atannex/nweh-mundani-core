<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Localization\Term;

/**
 * The day a calendar counts its periods from. Optional: without one, the
 * cyclic driver numbers cycle runs from the anchor in force (run 0 is the
 * run containing the anchor).
 */
final readonly class CalendarEpoch
{
    public function __construct(
        public CivilDate $date,
        public ?Term $label = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['date' => $this->date->toString(), 'label' => $this->label?->toArray()];
    }
}
