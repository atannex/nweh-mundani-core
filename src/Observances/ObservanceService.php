<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Finds the observances on a date: gathers candidates from every resolver,
 * drops those the viewer may not see, then keeps those with a matching rule.
 *
 * Order is deterministic: priority (high first), then identifier. When two
 * resolvers supply the same identifier, the tenant's own observance wins
 * over a global one, and otherwise the first resolver registered wins.
 */
final class ObservanceService
{
    /** @var list<ObservanceResolver> */
    private array $resolvers = [];

    public function __construct(
        private readonly RuleRegistry $rules,
        private readonly ObservanceGate $gate,
    ) {}

    public function addResolver(ObservanceResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Visible observances for a calendar, before any date filtering.
     *
     * @return list<Observance>
     */
    public function visible(CalendarContext $context, string $calendar, ?Authenticatable $viewer = null, bool $includeUnpublished = false): array
    {
        $byId = [];

        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->candidates($context, $calendar) as $observance) {
                if ($observance->calendar !== $calendar || (! $includeUnpublished && ! $observance->isPublished()) || ! $this->gate->allows($observance, $context, $viewer)) {
                    continue;
                }

                $existing = $byId[$observance->id] ?? null;

                if ($existing === null || ($existing->isGlobal() && ! $observance->isGlobal())) {
                    $byId[$observance->id] = $observance;
                }
            }
        }

        $list = array_values($byId);
        usort($list, static fn (Observance $a, Observance $b): int => [$b->priority, $a->id] <=> [$a->priority, $b->id]);

        return $list;
    }

    /**
     * Observances with a live (not cancelled) occurrence on the context's date.
     *
     * @param  list<Observance>  $observances
     * @param  Closure(CivilDate): RuleContext  $contextFor
     * @return list<Observance>
     */
    public function matching(array $observances, RuleContext $context, ?Closure $contextFor = null): array
    {
        $live = [];

        foreach ($this->occurrences($observances, $context, $contextFor) as $occurrence) {
            if (! $occurrence->isCancelled()) {
                $live[$occurrence->observance->id] = $occurrence->observance;
            }
        }

        return array_values($live);
    }

    /**
     * Occurrences on the context's date, in observance order:
     *
     *  - a rule matches and there is no override: scheduled
     *  - a rule matches and the date is cancelled: cancelled (still reported)
     *  - a rule matches and the date is rescheduled: nothing here; it appears on the target day
     *  - another date is rescheduled onto this one, and a rule matched there: rescheduled
     *
     * Overrides for dates the rules do not produce are ignored.
     *
     * @param  list<Observance>  $observances
     * @param  Closure(CivilDate): RuleContext  $contextFor  Rule context for another date (for reschedules).
     * @return list<ObservanceOccurrence>
     */
    public function occurrences(array $observances, RuleContext $context, ?Closure $contextFor = null): array
    {
        $found = [];
        $date = $context->date;

        foreach ($observances as $observance) {
            if ($this->occursOn($observance, $context)) {
                $override = $observance->overrideOn($date);

                if ($override === null) {
                    $found[] = new ObservanceOccurrence($observance, $date, $date, OccurrenceState::Scheduled);
                } elseif ($override->action === OverrideAction::Cancel) {
                    $found[] = new ObservanceOccurrence($observance, $date, $date, OccurrenceState::Cancelled, $override->reason);
                }
            }

            if ($contextFor === null) {
                continue;
            }

            foreach ($observance->rescheduledOnto($date) as $moved) {
                if ($this->occursOn($observance, $contextFor($moved->date))) {
                    $found[] = new ObservanceOccurrence($observance, $date, $moved->date, OccurrenceState::Rescheduled, $moved->reason);
                }
            }
        }

        return $found;
    }

    /** Whether any rule of the observance produces the context's date (overrides aside). */
    public function occursOn(Observance $observance, RuleContext $context): bool
    {
        foreach ($observance->rules as $rule) {
            if ($this->rules->matches($rule, $context)) {
                return true;
            }
        }

        return false;
    }
}
