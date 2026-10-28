<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Localization\Term;

/**
 * One named position in a cycle. Ordinals are 1-based and contiguous.
 */
final readonly class CyclePosition
{
    public function __construct(
        public string $id,
        public int $ordinal,
        public Term $label,
    ) {
        Identifier::assert($id, 'position identifier');
    }

    /** @return array{id: string, ordinal: int, label: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'ordinal' => $this->ordinal, 'label' => $this->label->toArray()];
    }
}
