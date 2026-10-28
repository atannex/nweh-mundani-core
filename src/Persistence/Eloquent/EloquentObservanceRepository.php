<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Domain\CalendarRule;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\ObservanceOverrideRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\ObservanceRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\ObservanceRuleRecord;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class EloquentObservanceRepository implements ObservanceRepository
{
    private ?bool $available = null;

    public function __construct(
        private readonly ObservanceFactory $factory,
        private readonly TermStore $terms = new TermStore,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = (new ObservanceRecord)->getConnection()->getSchemaBuilder()->hasTable('nmc_observances');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function forCalendar(string $tenant, string $calendar): array
    {
        return $this->hydrate($this->scope($tenant, $calendar)->with(['rules', 'overrides'])->orderBy('identifier')->get()->all());
    }

    public function all(string $tenant): array
    {
        return $this->hydrate(ObservanceRecord::query()->where('tenant_key', $tenant)->with(['rules', 'overrides'])->orderBy('calendar_identifier')->orderBy('identifier')->get()->all());
    }

    public function find(string $tenant, string $calendar, string $id): ?Observance
    {
        $row = $this->scope($tenant, $calendar)->where('identifier', $id)->with(['rules', 'overrides'])->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function save(Observance $observance): void
    {
        (new ObservanceRecord)->getConnection()->transaction(function () use ($observance): void {
            $row = ObservanceRecord::query()->updateOrCreate(
                ['tenant_key' => $observance->tenant, 'calendar_identifier' => $observance->calendar, 'identifier' => $observance->id],
                [
                    'category' => $observance->category,
                    'name_original' => $observance->name->original,
                    'name_language' => $observance->name->language,
                    'description_original' => $observance->description?->original,
                    'description_language' => $observance->description?->language,
                    'visibility' => $observance->visibility->value,
                    'verification_status' => $observance->verification->value,
                    'priority' => $observance->priority,
                    'source_ids' => $observance->sources,
                    'status' => $observance->status->value,
                ],
            );

            if ($observance->isPublished() && $row->published_at === null) {
                $row->published_at = now()->toImmutable();
                $row->save();
            }

            ObservanceOverrideRecord::query()->where('observance_id', $row->id)->delete();

            foreach ($observance->overrides as $override) {
                ObservanceOverrideRecord::query()->create([
                    'observance_id' => $row->id,
                    'occurrence_date' => $override->date->toString(),
                    'action' => $override->action->value,
                    'rescheduled_to' => $override->rescheduledTo?->toString(),
                    'reason' => $override->reason,
                ]);
            }

            $this->terms->forget('observance', [$row->id]);
            $this->terms->write('observance', $row->id, 'name', $observance->name);

            if ($observance->description !== null) {
                $this->terms->write('observance', $row->id, 'description', $observance->description);
            }

            ObservanceRuleRecord::query()->where('observance_id', $row->id)->delete();

            foreach ($observance->rules as $sort => $rule) {
                /** @var CalendarRule $rule */
                ObservanceRuleRecord::query()->create([
                    'observance_id' => $row->id,
                    'sort' => $sort,
                    'type' => $rule->type,
                    'parameters' => $rule->specification->toArray(),
                    'priority' => $rule->priority,
                    'effective_from' => $rule->effective->from?->toString(),
                    'effective_until' => $rule->effective->until?->toString(),
                ]);
            }
        });
    }

    public function delete(string $tenant, string $calendar, string $id): bool
    {
        $row = $this->scope($tenant, $calendar)->where('identifier', $id)->first();

        if ($row === null) {
            return false;
        }

        $this->terms->forget('observance', [$row->id]);

        return (bool) $row->delete();
    }

    /** @return Builder<ObservanceRecord> */
    private function scope(string $tenant, string $calendar): Builder
    {
        return ObservanceRecord::query()->where('tenant_key', $tenant)->where('calendar_identifier', $calendar);
    }

    /**
     * @param  array<int, ObservanceRecord>  $rows
     * @return list<Observance>
     */
    private function hydrate(array $rows): array
    {
        $this->terms->preload(['observance' => array_values(array_map(static fn (ObservanceRecord $r): int => $r->id, $rows))]);

        return array_values(array_map(fn (ObservanceRecord $row): Observance => $this->factory->fromArray([
            'id' => $row->identifier,
            'calendar' => $row->calendar_identifier,
            'name' => $this->terms->read('observance', $row->id, 'name', $row->name_original, $row->name_language),
            'description' => $row->description_original === null ? null : $this->terms->read('observance', $row->id, 'description', $row->description_original, $row->description_language),
            'category' => $row->category,
            'visibility' => $row->visibility,
            'verification' => $row->verification_status,
            'priority' => $row->priority,
            'sources' => $row->source_ids ?? [],
            'status' => $row->status,
            'overrides' => $row->overrides->map(static fn (ObservanceOverrideRecord $o): array => [
                'date' => EloquentCalendarRepository::day($o->occurrence_date),
                'action' => $o->action,
                'rescheduled_to' => EloquentCalendarRepository::day($o->rescheduled_to),
                'reason' => $o->reason,
            ])->all(),
            'rules' => $row->rules->map(static fn (ObservanceRuleRecord $r): array => [
                'type' => $r->type,
                'parameters' => $r->parameters,
                'priority' => $r->priority,
                'effective' => [
                    'from' => EloquentCalendarRepository::day($r->effective_from),
                    'until' => EloquentCalendarRepository::day($r->effective_until),
                ],
            ])->all(),
        ], $row->tenant_key), $rows));
    }
}
