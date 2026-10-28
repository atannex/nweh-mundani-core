<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Localization\Term;

/**
 * An explicit override for a single date: on this day the cycle is recorded
 * at a position other than (or confirming) the computed one. It changes that
 * date only; to re-align every later date, add an anchor with an effective range.
 */
final readonly class CalendarException
{
    /** @param list<string> $sources */
    public function __construct(
        public string $id,
        public CivilDate $date,
        public string $positionId,
        public ?Term $reason = null,
        public array $sources = [],
    ) {
        Identifier::assert($id, 'exception identifier');
        Identifier::assert($positionId, 'position identifier');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toString(),
            'position' => $this->positionId,
            'reason' => $this->reason?->toArray(),
            'sources' => $this->sources,
        ];
    }
}
