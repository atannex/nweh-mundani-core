<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Dates\CivilDate;

/**
 * One computed occurrence of an observance: the day it falls on, the day its
 * rule put it on (different when rescheduled), and its state.
 */
final readonly class ObservanceOccurrence
{
    public function __construct(
        public Observance $observance,
        public CivilDate $date,
        public CivilDate $scheduledFor,
        public OccurrenceState $state,
        public ?string $reason = null,
    ) {}

    public function isCancelled(): bool
    {
        return $this->state === OccurrenceState::Cancelled;
    }
}
