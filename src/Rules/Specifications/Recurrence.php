<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Specifications;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;

final readonly class Recurrence implements RuleSpecification
{
    public const array FREQUENCIES = ['daily', 'monthly', 'yearly'];

    public function __construct(
        public string $frequency,
        public int $interval,
        public CivilDate $start,
        public ?int $count = null,
    ) {}

    public function toArray(): array
    {
        return ['frequency' => $this->frequency, 'interval' => $this->interval, 'start' => $this->start->toString(), 'count' => $this->count];
    }
}
