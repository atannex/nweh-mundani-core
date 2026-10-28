<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

use Atannex\NwehMundani\Core\Dates\CycleReading;

/** Where one cycle stands on one day. */
final readonly class DayCycleData extends Data
{
    public function __construct(
        public string $cycle,
        public string $position,
        public int $ordinal,
        public int $length,
        public string $label,
        public string $original,
        public int $sequence,
        public string $certainty,
        public ?string $anchor,
        public ?string $exception,
    ) {}

    public static function from(CycleReading $reading, string $label): self
    {
        return new self($reading->cycleId, $reading->positionId(), $reading->ordinal(), $reading->length, $label, $reading->original(), $reading->sequence, $reading->certainty->value, $reading->anchorId, $reading->exceptionId);
    }

    public function toArray(): array
    {
        return [
            'cycle' => $this->cycle,
            'position' => $this->position,
            'ordinal' => $this->ordinal,
            'length' => $this->length,
            'label' => $this->label,
            'original' => $this->original,
            'sequence' => $this->sequence,
            'certainty' => $this->certainty,
            'anchor' => $this->anchor,
            'exception' => $this->exception,
        ];
    }
}
