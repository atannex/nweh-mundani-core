<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/** A cycle's full structure, for detail and administration pages. */
final readonly class CycleData extends Data
{
    /**
     * @param  list<array{id: string, ordinal: int, label: string, original: string, term: array<string, mixed>}>  $positions
     * @param  list<array<string, mixed>>  $anchors
     * @param  list<array<string, mixed>>  $exceptions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $original,
        public int $length,
        public string $continuity,
        public ?string $effectiveFrom,
        public ?string $effectiveUntil,
        public array $positions,
        public array $anchors,
        public array $exceptions,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'original' => $this->original,
            'length' => $this->length,
            'continuity' => $this->continuity,
            'effective' => ['from' => $this->effectiveFrom, 'until' => $this->effectiveUntil],
            'positions' => $this->positions,
            'anchors' => $this->anchors,
            'exceptions' => $this->exceptions,
        ];
    }
}
