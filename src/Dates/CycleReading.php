<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use Atannex\NwehMundani\Core\Domain\CyclePosition;

/**
 * Where one cycle stands on one civil date.
 */
final readonly class CycleReading
{
    /**
     * @param  int  $index  Zero-based position index (ordinal - 1).
     * @param  int  $length  Cycle length, so callers never need the definition.
     * @param  int  $sequence  Which run of the cycle this is, counted from the epoch (or the anchor's run = 0); negative before it.
     * @param  string|null  $anchorId  Anchor used, or null when an override applied.
     * @param  string|null  $exceptionId  Override that set the position, if any.
     */
    public function __construct(
        public string $cycleId,
        public CyclePosition $position,
        public int $index,
        public int $length,
        public int $sequence,
        public Certainty $certainty,
        public ?string $anchorId = null,
        public ?string $exceptionId = null,
    ) {}

    /** The position's name in a locale, falling back to the exact original spelling. */
    public function label(?string $locale = null): string
    {
        return $this->position->label->label($locale);
    }

    public function original(): string
    {
        return $this->position->label->original;
    }

    public function positionId(): string
    {
        return $this->position->id;
    }

    public function ordinal(): int
    {
        return $this->position->ordinal;
    }

    /** @return array<string, mixed> */
    public function toArray(?string $locale = null): array
    {
        return [
            'cycle' => $this->cycleId,
            'position' => $this->position->id,
            'ordinal' => $this->position->ordinal,
            'length' => $this->length,
            'label' => $this->label($locale),
            'original' => $this->original(),
            'sequence' => $this->sequence,
            'certainty' => $this->certainty->value,
            'anchor' => $this->anchorId,
            'exception' => $this->exceptionId,
        ];
    }
}
