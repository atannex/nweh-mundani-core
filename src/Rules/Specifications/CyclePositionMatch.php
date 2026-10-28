<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Specifications;

use Atannex\NwehMundani\Core\Rules\RuleSpecification;

final readonly class CyclePositionMatch implements RuleSpecification
{
    /** @param list<string> $positions */
    public function __construct(
        public string $cycle,
        public array $positions,
    ) {}

    public function toArray(): array
    {
        return ['cycle' => $this->cycle, 'positions' => $this->positions];
    }
}
