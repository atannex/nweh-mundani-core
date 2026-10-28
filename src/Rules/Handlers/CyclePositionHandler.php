<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;
use Atannex\NwehMundani\Core\Rules\Specifications\CyclePositionMatch;
use InvalidArgumentException;

/**
 * "cycle-position": every day a cycle stands at one of the given positions
 * ({cycle, positions: [...]}), for example a recurring market day.
 */
final class CyclePositionHandler implements RuleHandler
{
    public function type(): string
    {
        return 'cycle-position';
    }

    public function parse(array $parameters): RuleSpecification|array
    {
        $p = new Parameters($parameters);
        $p->onlyKeys(['cycle', 'positions']);
        $cycle = $p->identifier('cycle');
        $positions = [];

        foreach ($p->list('positions', 1000) ?? [] as $i => $value) {
            if (! is_string($value) || ! Identifier::isValid($value)) {
                $p->fail("positions.{$i}", 'must be a position identifier.');

                continue;
            }

            $positions[] = $value;
        }

        return $p->ok() && $cycle !== null ? new CyclePositionMatch($cycle, array_values(array_unique($positions))) : $p->violations;
    }

    public function matches(RuleSpecification $specification, RuleContext $context): bool
    {
        if (! $specification instanceof CyclePositionMatch) {
            throw new InvalidArgumentException('Expected a CyclePositionMatch specification.');
        }

        $reading = $context->calendarDate?->cycles[$specification->cycle] ?? null;

        return $reading !== null && in_array($reading->positionId(), $specification->positions, true);
    }

    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array
    {
        if (! $specification instanceof CyclePositionMatch) {
            return [];
        }

        $violations = [];

        foreach ($specification->positions as $position) {
            array_push($violations, ...Parameters::checkPosition($definition, $specification->cycle, $position, 'parameters.positions'));
        }

        return $violations;
    }
}
