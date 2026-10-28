<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Specifications;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;

final readonly class FixedDate implements RuleSpecification
{
    public function __construct(
        public ?CivilDate $date = null,
        public ?int $month = null,
        public ?int $day = null,
    ) {}

    public function toArray(): array
    {
        return $this->date !== null ? ['date' => $this->date->toString()] : ['month' => $this->month, 'day' => $this->day];
    }
}
