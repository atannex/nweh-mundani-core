<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleSpecification;
use Atannex\NwehMundani\Core\Rules\Specifications\Relative;
use InvalidArgumentException;
use WeakMap;

/**
 * "relative": a date defined from a base date.
 *
 *   base        {date} once, or {month, day} every year
 *   cycle,      optional: step to the nth day the cycle stands at `position`,
 *   position,   counting from the base (occurrence 1 = on or after the base,
 *   occurrence  -1 = on or before it)
 *   offset      days to add at the end (default 0)
 *
 * Example: "the third Ankoah on or after 1 December, plus one day".
 */
final class RelativeHandler implements RuleHandler
{
    /**
     * Resolved targets per specification and definition, keyed by base day
     * number. Deterministic (same spec + same definition → same target), and
     * weakly keyed so entries disappear with the objects. Turns a year view
     * from O(days × bases × walk) into O(bases × walk).
     *
     * @var WeakMap<RuleSpecification, WeakMap<CalendarDefinition, array<int, int|null>>>
     */
    private WeakMap $targets;

    public function __construct()
    {
        $this->targets = new WeakMap;
    }

    public function type(): string
    {
        return 'relative';
    }

    public function parse(array $parameters): RuleSpecification|array
    {
        $p = new Parameters($parameters);
        $p->onlyKeys(['base', 'cycle', 'position', 'occurrence', 'offset']);
        $offset = $p->int('offset', -36600, 36600, required: false, default: 0) ?? 0;
        $base = $p->object('base');

        $baseDate = null;
        $baseMonth = null;
        $baseDay = null;

        if ($base !== null) {
            $b = new Parameters($base, 'parameters.base');

            if ($b->has('date')) {
                $baseDate = $b->date('date');
            } else {
                $baseMonth = $b->int('month', 1, 12);
                $baseDay = $b->int('day', 1, 31);

                if ($baseMonth !== null && $baseDay !== null && $baseDay > CivilDate::daysInMonthOf(2000, $baseMonth)) {
                    $b->fail('day', "month {$baseMonth} has no day {$baseDay}.");
                }
            }

            array_push($p->violations, ...$b->violations);
        }

        $cycle = null;
        $position = null;
        $occurrence = null;

        if ($p->has('cycle') || $p->has('position') || $p->has('occurrence')) {
            $cycle = $p->identifier('cycle');
            $position = $p->identifier('position');
            $occurrence = $p->int('occurrence', -1000, 1000, required: false, default: 1);

            if ($occurrence === 0) {
                $p->fail('occurrence', 'must not be 0 (use 1 for on-or-after, -1 for on-or-before).');
            }
        }

        return $p->ok()
            ? new Relative($baseDate, $baseMonth, $baseDay, $cycle, $position, $occurrence, $offset)
            : $p->violations;
    }

    public function matches(RuleSpecification $specification, RuleContext $context): bool
    {
        if (! $specification instanceof Relative) {
            throw new InvalidArgumentException('Expected a Relative specification.');
        }

        $length = $specification->cycle === null ? 0 : ($context->definition->cycle($specification->cycle)?->length() ?? 0);

        $memo = $this->targets[$specification] ?? null;

        if ($memo === null) {
            /** @var WeakMap<CalendarDefinition, array<int, int|null>> $memo */
            $memo = new WeakMap;
            $this->targets[$specification] = $memo;
        }
        $known = $memo[$context->definition] ?? [];

        foreach ($this->bases($specification, $context->date, $length) as $base) {
            if (! array_key_exists($base->dayNumber, $known)) {
                $known[$base->dayNumber] = $this->resolve($specification, $base, $context)?->dayNumber;
            }

            if ($known[$base->dayNumber] === $context->date->dayNumber) {
                $memo[$context->definition] = $known;

                return true;
            }
        }

        $memo[$context->definition] = $known;

        return false;
    }

    public function validateAgainst(RuleSpecification $specification, CalendarDefinition $definition): array
    {
        if (! $specification instanceof Relative || $specification->cycle === null || $specification->position === null) {
            return [];
        }

        return Parameters::checkPosition($definition, $specification->cycle, $specification->position, 'parameters.position');
    }

    /**
     * Candidate base dates that could land on $date: the fixed base, or the
     * yearly base in the years around $date, wide enough to cover the offset
     * plus the longest possible walk to the nth occurrence.
     *
     * @return list<CivilDate>
     */
    private function bases(Relative $rule, CivilDate $date, int $cycleLength): array
    {
        if ($rule->baseDate !== null) {
            return [$rule->baseDate];
        }

        $reach = 2 + intdiv(abs($rule->offset) + abs($rule->occurrence ?? 1) * $cycleLength, 365);
        $bases = [];

        for ($year = $date->year - $reach; $year <= $date->year + $reach; $year++) {
            if ($year < CivilDate::MIN_YEAR || $year > CivilDate::MAX_YEAR || $rule->baseDay === null || $rule->baseMonth === null) {
                continue;
            }

            if ($rule->baseDay <= CivilDate::daysInMonthOf($year, $rule->baseMonth)) {
                $bases[] = new CivilDate($year, $rule->baseMonth, $rule->baseDay);
            }
        }

        return $bases;
    }

    private function resolve(Relative $rule, CivilDate $base, RuleContext $context): ?CivilDate
    {
        $cursor = $base;

        if ($rule->cycle !== null && $rule->position !== null) {
            $remaining = abs($rule->occurrence ?? 1);
            $forward = ($rule->occurrence ?? 1) > 0;
            $step = $forward ? $context->next : $context->previous;
            $inclusive = true;

            while ($remaining-- > 0) {
                $found = $step($rule->cycle, $rule->position, $cursor, $inclusive);

                if ($found === null) {
                    return null;
                }

                $cursor = $found;
                $inclusive = false;
            }
        }

        return $cursor->addDays($rule->offset);
    }
}
