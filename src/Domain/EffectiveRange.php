<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use InvalidArgumentException;

/**
 * When something is in force: inclusive bounds, either of which may be open.
 */
final readonly class EffectiveRange
{
    public function __construct(
        public ?CivilDate $from = null,
        public ?CivilDate $until = null,
    ) {
        if ($from !== null && $until !== null && $until->isBefore($from)) {
            throw new InvalidArgumentException("Effective range ends ({$until}) before it starts ({$from}).");
        }
    }

    public static function always(): self
    {
        return new self;
    }

    public function contains(CivilDate $date): bool
    {
        return ($this->from === null || ! $date->isBefore($this->from))
            && ($this->until === null || ! $date->isAfter($this->until));
    }

    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = $this->from === null || $other->until === null || ! $this->from->isAfter($other->until);
        $otherStartsBeforeThisEnds = $other->from === null || $this->until === null || ! $other->from->isAfter($this->until);

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }

    public function isUnbounded(): bool
    {
        return $this->from === null && $this->until === null;
    }

    /** @return array{from: ?string, until: ?string} */
    public function toArray(): array
    {
        return ['from' => $this->from?->toString(), 'until' => $this->until?->toString()];
    }

    public function __toString(): string
    {
        return ($this->from?->toString() ?? '…').' → '.($this->until?->toString() ?? '…');
    }
}
