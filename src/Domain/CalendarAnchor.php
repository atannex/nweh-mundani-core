<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Dates\CivilDate;

/**
 * A recorded correspondence: on this civil date the cycle stood at this
 * position. A cycle may have several anchors; each applies within its own
 * effective range, which lets a definition record a historical re-alignment.
 */
final readonly class CalendarAnchor
{
    /** @param list<string> $sources Source identifiers that attest this correspondence. */
    public function __construct(
        public string $id,
        public CivilDate $date,
        public string $positionId,
        public EffectiveRange $effective = new EffectiveRange,
        public array $sources = [],
    ) {
        Identifier::assert($id, 'anchor identifier');
        Identifier::assert($positionId, 'position identifier');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toString(),
            'position' => $this->positionId,
            'effective' => $this->effective->toArray(),
            'sources' => $this->sources,
        ];
    }
}
