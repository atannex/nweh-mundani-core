<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Specifications;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;

final readonly class Relative implements RuleSpecification
{
    public function __construct(
        public ?CivilDate $baseDate,
        public ?int $baseMonth,
        public ?int $baseDay,
        public ?string $cycle,
        public ?string $position,
        public ?int $occurrence,
        public int $offset,
    ) {}

    public function toArray(): array
    {
        return [
            'base' => $this->baseDate !== null ? ['date' => $this->baseDate->toString()] : ['month' => $this->baseMonth, 'day' => $this->baseDay],
            'cycle' => $this->cycle,
            'position' => $this->position,
            'occurrence' => $this->occurrence,
            'offset' => $this->offset,
        ];
    }
}
