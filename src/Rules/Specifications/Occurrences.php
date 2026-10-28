<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Specifications;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;

final readonly class Occurrences implements RuleSpecification
{
    /** @var array<int, true> */
    private array $index;

    /** @param list<CivilDate> $dates */
    public function __construct(public array $dates)
    {
        $index = [];

        foreach ($dates as $date) {
            $index[$date->dayNumber] = true;
        }

        $this->index = $index;
    }

    public function includes(CivilDate $date): bool
    {
        return isset($this->index[$date->dayNumber]);
    }

    public function toArray(): array
    {
        return ['dates' => array_map(static fn (CivilDate $d): string => $d->toString(), $this->dates)];
    }
}
