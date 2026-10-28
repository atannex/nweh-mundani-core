<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Events\ObservanceCreated;
use Atannex\NwehMundani\Core\Events\ObservanceOccurrenceChanged;
use Atannex\NwehMundani\Core\Events\ObservancePublished;
use Atannex\NwehMundani\Core\Events\ObservancesChanged;
use Atannex\NwehMundani\Core\Events\ObservanceStatusChanged;
use Atannex\NwehMundani\Core\Events\ObservanceUpdated;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Exceptions\ObservanceNotFound;
use Atannex\NwehMundani\Core\Exceptions\TenantViolation;
use Atannex\NwehMundani\Core\Governance\Actor;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\Violation;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Observances\ObservanceOverride;
use Atannex\NwehMundani\Core\Observances\ObservanceStatus;
use Atannex\NwehMundani\Core\Observances\OverrideAction;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Observance lifecycle and per-occurrence changes.
 *
 * An observance is a rule ("every Ankoah"); its occurrences are computed on
 * demand and never stored. Changing one occurrence (cancel, reschedule,
 * restore) stores a single override row for that date, not a row per day.
 *
 * Writes need nweh-mundani.manage-observances for the context's tenant. An
 * observance can only be attached to a calendar the context can resolve,
 * so one tenant can never attach data to another tenant's calendar.
 */
final readonly class ObservanceManagementService
{
    public function __construct(
        private CalendarManager $manager,
        private ObservanceRepository $observances,
        private ObservanceFactory $factory,
        private RuleRegistry $rules,
        private AuditTrail $audit,
        private UnitOfWork $unitOfWork,
        private CalendarAuthorizer $authorizer,
        private DefinitionCache $cache,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Create an observance. It starts as a draft (readers do not see it)
     * unless $publish is true.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null, bool $publish = false): Observance
    {
        $context = $this->context($context);
        $observance = $this->factory->fromArray([...$data, 'status' => ($publish ? ObservanceStatus::Published : ObservanceStatus::Draft)->value, 'overrides' => []], $context->tenant);

        if ($this->observances->find($context->tenant, $observance->calendar, $observance->id) !== null) {
            throw InvalidDefinition::single("observance {$observance->id}", 'id', 'already exists for this calendar; use update().');
        }

        $saved = $this->save($observance, $actor, $context, 'created');
        $this->events?->dispatch(new ObservanceCreated($context->tenant, $saved->calendar, $saved->id, Actor::name($actor)));

        if ($publish) {
            $this->events?->dispatch(new ObservancePublished($context->tenant, $saved->calendar, $saved->id, Actor::name($actor)));
        }

        return $saved;
    }

    /**
     * Change an observance's definition. Its status and overrides are kept
     * unless given; its id and calendar cannot change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(string $calendarId, string $observanceId, array $changes, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Observance
    {
        $context = $this->context($context);
        $current = $this->find($context, $calendarId, $observanceId);

        if (isset($changes['id']) || isset($changes['calendar']) || isset($changes['tenant'])) {
            throw InvalidDefinition::single("observance {$observanceId}", 'id', 'id, calendar and tenant cannot change; create a new observance.');
        }

        $updated = $this->factory->fromArray(array_replace($current->toArray(), $changes), $context->tenant);
        $saved = $this->save($updated, $actor, $context, 'updated');
        $this->events?->dispatch(new ObservanceUpdated($context->tenant, $calendarId, $observanceId, Actor::name($actor)));

        return $saved;
    }

    public function publish(string $calendarId, string $observanceId, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Observance
    {
        $observance = $this->transition($calendarId, $observanceId, ObservanceStatus::Published, $actor, $context);
        $this->events?->dispatch(new ObservancePublished($observance->tenant, $calendarId, $observanceId, Actor::name($actor)));

        return $observance;
    }

    /** Withdraw an observance as a whole (to cancel one date, use cancelOccurrence()). */
    public function cancel(string $calendarId, string $observanceId, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Observance
    {
        return $this->transition($calendarId, $observanceId, ObservanceStatus::Cancelled, $actor, $context);
    }

    public function unpublish(string $calendarId, string $observanceId, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Observance
    {
        return $this->transition($calendarId, $observanceId, ObservanceStatus::Draft, $actor, $context);
    }

    public function cancelOccurrence(string $calendarId, string $observanceId, CivilDate|string|DateTimeInterface $date, Authenticatable|SystemActor|null $actor, ?string $reason = null, ?CalendarContext $context = null): Observance
    {
        return $this->override($calendarId, $observanceId, $date, $actor, $context, static fn (CivilDate $d): ObservanceOverride => new ObservanceOverride($d, OverrideAction::Cancel, null, $reason));
    }

    public function rescheduleOccurrence(string $calendarId, string $observanceId, CivilDate|string|DateTimeInterface $date, CivilDate|string|DateTimeInterface $to, Authenticatable|SystemActor|null $actor, ?string $reason = null, ?CalendarContext $context = null): Observance
    {
        $target = CivilDate::from($to, $this->context($context)->timezone);

        return $this->override($calendarId, $observanceId, $date, $actor, $context, static fn (CivilDate $d): ObservanceOverride => new ObservanceOverride($d, OverrideAction::Reschedule, $target, $reason));
    }

    /** Remove the override for a date, so the rule applies again. */
    public function restoreOccurrence(string $calendarId, string $observanceId, CivilDate|string|DateTimeInterface $date, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Observance
    {
        return $this->override($calendarId, $observanceId, $date, $actor, $context, null);
    }

    public function delete(string $calendarId, string $observanceId, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): bool
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_OBSERVANCES, $actor, $context->tenant, "{$calendarId}/{$observanceId}");
        $who = Actor::name($actor);

        $deleted = $this->unitOfWork->run(function () use ($calendarId, $observanceId, $context, $who): bool {
            $deleted = $this->observances->delete($context->tenant, $calendarId, $observanceId);

            if ($deleted) {
                $this->audit->recordRevision(new RevisionEntry($context->tenant, 'observance', "{$calendarId}/{$observanceId}", 'deleted', $who));
            }

            return $deleted;
        });

        if ($deleted) {
            $this->cache->forgetObservances($context->tenant, $calendarId);
            $this->events?->dispatch(new ObservancesChanged($context->tenant, $calendarId, $observanceId, 'deleted', $who));
        }

        return $deleted;
    }

    /**
     * Management view: every observance of a calendar in this tenant,
     * drafts and cancelled ones included.
     *
     * @return list<Observance>
     */
    public function all(string $calendarId, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): array
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_OBSERVANCES, $actor, $context->tenant, $calendarId);

        return $this->observances->forCalendar($context->tenant, $calendarId);
    }

    /**
     * Save as-is (status from the data; published when absent). Kept for
     * CalendarAdministration::saveObservance() compatibility.
     */
    public function save(Observance $observance, Authenticatable|SystemActor|null $actor, CalendarContext $context, string $action = 'saved'): Observance
    {
        if ($observance->tenant !== $context->tenant) {
            throw TenantViolation::crossing($context->tenant, $observance->tenant, "Observance [{$observance->id}]");
        }

        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_OBSERVANCES, $actor, $context->tenant, "{$observance->calendar}/{$observance->id}");

        // Resolving through the context means a tenant can only attach
        // observances to its own calendars or global ones.
        $calendar = $this->manager->withContext($context)->calendar($observance->calendar)->definition();
        $violations = [];

        foreach ($observance->rules as $i => $rule) {
            foreach ($this->rules->validateAgainst($rule, $calendar) as $violation) {
                $violations[] = new Violation("rules.{$i}.{$violation->path}", $violation->message);
            }
        }

        if ($violations !== []) {
            throw new InvalidDefinition("observance {$observance->id}", $violations);
        }

        $who = Actor::name($actor);

        $this->unitOfWork->run(function () use ($observance, $who, $action): void {
            $this->observances->save($observance);
            $this->audit->recordRevision(new RevisionEntry($observance->tenant, 'observance', "{$observance->calendar}/{$observance->id}", $action, $who, [
                'status' => $observance->status->value,
                'hash' => hash('sha256', json_encode($observance->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            ]));
        });

        $this->cache->forgetObservances($observance->tenant, $observance->calendar);
        $this->events?->dispatch(new ObservancesChanged($observance->tenant, $observance->calendar, $observance->id, $action, $who));

        return $observance;
    }

    private function transition(string $calendarId, string $observanceId, ObservanceStatus $to, Authenticatable|SystemActor|null $actor, ?CalendarContext $context): Observance
    {
        $context = $this->context($context);
        $current = $this->find($context, $calendarId, $observanceId);

        if (! $current->status->canMoveTo($to)) {
            throw InvalidTransition::between("Observance [{$observanceId}]", $current->status->value, $to->value, array_map(static fn (ObservanceStatus $s): string => $s->value, $current->status->allowedNext()));
        }

        $saved = $this->save($current->with(status: $to), $actor, $context, "status-{$to->value}");
        $this->events?->dispatch(new ObservanceStatusChanged($context->tenant, $calendarId, $observanceId, $current->status->value, $to->value, Actor::name($actor)));

        return $saved;
    }

    /** @param (callable(CivilDate): ObservanceOverride)|null $make null removes the override */
    private function override(string $calendarId, string $observanceId, CivilDate|string|DateTimeInterface $date, Authenticatable|SystemActor|null $actor, ?CalendarContext $context, ?callable $make): Observance
    {
        $context = $this->context($context);
        $current = $this->find($context, $calendarId, $observanceId);
        $day = CivilDate::from($date, $context->timezone);
        $calendar = $this->manager->withContext($context)->calendar($calendarId);

        // Only a date the rules actually produce can be cancelled or moved.
        if ($make !== null && ! $calendar->observanceService()->occursOn($current, $calendar->ruleContext($day))) {
            throw InvalidDefinition::single("observance {$observanceId}", 'date', "the observance does not occur on {$day}; there is nothing to change.");
        }

        $overrides = array_values(array_filter($current->overrides, static fn (ObservanceOverride $o): bool => ! $o->date->equals($day)));

        if ($make !== null) {
            $overrides[] = $make($day);
            usort($overrides, static fn (ObservanceOverride $a, ObservanceOverride $b): int => $a->date->compareTo($b->date));
        } elseif (count($overrides) === count($current->overrides)) {
            throw InvalidDefinition::single("observance {$observanceId}", 'date', "there is no change recorded for {$day}.");
        }

        $saved = $this->save($current->with(overrides: $overrides), $actor, $context, 'occurrence-changed');

        foreach ($make === null ? [null] : array_filter($overrides, static fn (ObservanceOverride $o): bool => $o->date->equals($day)) as $override) {
            $this->events?->dispatch(new ObservanceOccurrenceChanged(
                $context->tenant, $calendarId, $observanceId, $day->toString(),
                $override === null ? 'restore' : $override->action->value,
                $override?->rescheduledTo?->toString(),
                Actor::name($actor),
            ));
        }

        return $saved;
    }

    private function find(CalendarContext $context, string $calendarId, string $observanceId): Observance
    {
        return $this->observances->find($context->tenant, $calendarId, $observanceId)
            ?? throw ObservanceNotFound::forIdentifier($calendarId, $observanceId);
    }

    private function context(?CalendarContext $context): CalendarContext
    {
        return $context ?? $this->manager->context();
    }
}
