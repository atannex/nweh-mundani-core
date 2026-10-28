<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;
use Atannex\NwehMundani\Core\Rules\Specifications\Occurrences;
use InvalidArgumentException;

/**
 * "occurrences": an explicit list of dates on which something is recorded
 * to have happened (verified historical occurrences). No extrapolation.
 */
final class OccurrencesHandler implements RuleHandler
{
    public function type(): string
    {
        return 'occurrences';
    }

    public function parse(array $parameters): RuleSpecification|array
    {
        $p = new Parameters($parameters);
        $p->onlyKeys(['dates']);
        $dates = [];

        foreach ($p->list('dates') ?? [] as $i => $value) {
            try {
                $dates[] = is_string($value) ? CivilDate::parse($value) : throw new InvalidArgumentException('must be a YYYY-MM-DD string.');
            } catch (InvalidArgumentException $e) {
                $p->fail("dates.{$i}", $e->getMessage());
            }
        }

        return $p->ok() ? new Occurrences($dates) : $p->violations;
    }

    public function matches(RuleSpecification $specification, RuleContext $context): bool
    {
        if (! $specification instanceof Occurrences) {
            throw new InvalidArgumentException('Expected an Occurrences specification.');
        }

        return $specification->includes($context->date);
    }

    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array
    {
        return [];
    }
}
