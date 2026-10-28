<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\Violation;

/**
 * Interprets one rule type. Rules stored in the database are data (a type
 * key and parameters); only handlers registered by trusted code give them
 * meaning, and nothing in a rule is ever evaluated as code.
 */
interface RuleHandler
{
    /** The rule type key ("fixed-date"). */
    public function type(): string;

    /**
     * Validate raw parameters and turn them into a typed specification.
     *
     * @param  array<string, mixed>  $parameters
     * @return RuleSpecification|list<Violation>
     */
    public function parse(array $parameters): RuleSpecification|array;

    public function matches(RuleSpecification $specification, RuleContext $context): bool;

    /**
     * Checks that need the calendar (cycle and position identifiers exist).
     *
     * @return list<Violation>
     */
    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array;
}
