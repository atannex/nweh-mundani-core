<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Rules\RuleSpecification;

/**
 * A rule attached to an observance: which handler interprets it, its typed
 * parameters, when it is in force and how it ranks against other rules.
 */
final readonly class CalendarRule
{
    public function __construct(
        public string $type,
        public RuleSpecification $specification,
        public EffectiveRange $effective = new EffectiveRange,
        public int $priority = 0,
    ) {
        Identifier::assert($type, 'rule type');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'parameters' => $this->specification->toArray(),
            'effective' => $this->effective->toArray(),
            'priority' => $this->priority,
        ];
    }
}
