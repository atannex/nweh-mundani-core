<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Domain\EffectiveRange;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\AnchorRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CalendarNameRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CalendarRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CalendarVersionRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CycleRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\ExceptionRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\Record;
use Atannex\NwehMundani\Core\Persistence\PublicationResult;
use Atannex\NwehMundani\Core\Persistence\VersionSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Default storage: one row per calendar, one per version, and relational
 * rows for cycles, positions, anchors and overrides. Loaded rows go back
 * through CalendarFactory, so stored data is validated like any other input.
 */
final class EloquentCalendarRepository implements CalendarRepository
{
    private const array RELATIONS = ['calendar', 'names', 'cycles.positions', 'cycles.anchors.position', 'cycles.exceptions.position'];

    private ?bool $available = null;

    public function __construct(
        private readonly CalendarFactory $factory,
        private readonly TermStore $terms = new TermStore,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = (new CalendarVersionRecord)->getConnection()->getSchemaBuilder()->hasTable('nmc_calendar_versions');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function findPublished(string $tenant, string $id): ?CalendarDefinition
    {
        $row = $this->versionsOf($tenant, $id)->where('status', VersionStatus::Published->value)->with(self::RELATIONS)->first();

        return $row === null ? null : $this->hydrate([$row], $tenant)[0];
    }

    public function findVersion(string $tenant, string $id, string $version): ?CalendarDefinition
    {
        $row = $this->versionsOf($tenant, $id)->where('version', $version)->with(self::RELATIONS)->first();

        return $row === null ? null : $this->hydrate([$row], $tenant)[0];
    }

    public function published(string $tenant): array
    {
        $rows = CalendarVersionRecord::query()
            ->where('status', VersionStatus::Published->value)
            ->whereHas('calendar', static fn (Builder $q) => $q->where('tenant_key', $tenant))
            ->with(self::RELATIONS)
            ->get();

        return $this->hydrate($rows->all(), $tenant);
    }

    public function versions(string $tenant, string $id): array
    {
        $summaries = $this->versionsOf($tenant, $id)->get()->map(static fn (CalendarVersionRecord $row): VersionSummary => self::summary($id, $row))->all();

        usort($summaries, static fn (VersionSummary $a, VersionSummary $b): int => version_compare($a->version, $b->version));

        return $summaries;
    }

    public function saveDraft(CalendarDefinition $definition): void
    {
        $connection = (new CalendarVersionRecord)->getConnection();

        $connection->transaction(function () use ($definition): void {
            $calendar = CalendarRecord::query()->firstOrCreate(['tenant_key' => $definition->tenant(), 'identifier' => $definition->id()]);

            $version = CalendarVersionRecord::query()
                ->where('calendar_id', $calendar->id)
                ->where('version', $definition->version->number)
                ->lockForUpdate()
                ->first();

            if ($version !== null && $version->status !== VersionStatus::Draft->value) {
                throw ImmutableVersion::forVersion($definition->id(), $definition->version->number, $version->status);
            }

            $columns = $this->versionColumns($definition);

            if ($version === null) {
                $version = CalendarVersionRecord::query()->create(['calendar_id' => $calendar->id, 'version' => $definition->version->number, ...$columns]);
            } else {
                $this->clearTree($version);
                $version->update($columns);
            }

            $this->terms->write('version', $version->id, 'name', $definition->name);
            $this->writeNames($version, $definition);
            $this->writeCycles($version, $definition);
        });
    }

    public function publish(string $tenant, string $id, string $version, ?string $actor = null): PublicationResult
    {
        $connection = (new CalendarVersionRecord)->getConnection();

        return $connection->transaction(function () use ($tenant, $id, $version, $actor): PublicationResult {
            // Row locks serialise concurrent publications of the same calendar
            // (no-ops on SQLite, which serialises writers anyway); the status
            // check below then rejects whichever publication arrives second.
            $row = $this->versionsOf($tenant, $id)->where('version', $version)->lockForUpdate()->first()
                ?? throw CalendarNotFound::forIdentifier($id, $tenant, $version);
            $status = VersionStatus::from($row->status);

            if ($status->isReleased()) {
                throw ImmutableVersion::forVersion($id, $version, $row->status);
            }

            if (! $status->canMoveTo(VersionStatus::Published)) {
                throw InvalidTransition::between("Calendar [{$id}] version [{$version}]", $status->value, VersionStatus::Published->value, array_map(static fn (VersionStatus $s): string => $s->value, $status->allowedNext()));
            }

            $previous = $this->versionsOf($tenant, $id)->where('status', VersionStatus::Published->value)->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->status = VersionStatus::Superseded->value;
                $previous->superseded_at = now()->toImmutable();
                $previous->save();
            }

            $row->status = VersionStatus::Published->value;
            $row->published_at = now()->toImmutable();
            $row->published_by = $actor;
            $row->save();

            $definition = $this->findVersion($tenant, $id, $version) ?? throw CalendarNotFound::forIdentifier($id, $tenant, $version);

            return new PublicationResult($definition, $previous?->version);
        });
    }

    public function transition(string $tenant, string $id, string $version, VersionStatus $to, ?string $actor = null, ?string $notes = null): VersionSummary
    {
        return (new CalendarVersionRecord)->getConnection()->transaction(function () use ($tenant, $id, $version, $to, $actor, $notes): VersionSummary {
            $row = $this->versionsOf($tenant, $id)->where('version', $version)->lockForUpdate()->first()
                ?? throw CalendarNotFound::forIdentifier($id, $tenant, $version);
            $from = VersionStatus::from($row->status);

            if ($to === VersionStatus::Published || $to === VersionStatus::Superseded || ! $from->canMoveTo($to)) {
                throw InvalidTransition::between("Calendar [{$id}] version [{$version}]", $from->value, $to->value, array_map(static fn (VersionStatus $s): string => $s->value, $from->allowedNext()));
            }

            $row->status = $to->value;

            if ($to === VersionStatus::UnderReview) {
                $row->submitted_at = now()->toImmutable();
                $row->submitted_by = $actor;
            } else {
                $row->reviewed_at = now()->toImmutable();
                $row->reviewed_by = $actor;
            }

            if ($notes !== null) {
                $row->review_notes = $notes;
            }

            $row->save();

            return self::summary($id, $row);
        });
    }

    public function calendarStatus(string $tenant, string $id): ?CalendarStatus
    {
        $status = CalendarRecord::query()->where('tenant_key', $tenant)->where('identifier', $id)->value('status');

        return is_string($status) ? CalendarStatus::from($status) : null;
    }

    public function calendarStatuses(string $tenant): array
    {
        $statuses = [];

        foreach (CalendarRecord::query()->where('tenant_key', $tenant)->orderBy('identifier')->get(['identifier', 'status']) as $row) {
            $statuses[$row->identifier] = CalendarStatus::from($row->status);
        }

        return $statuses;
    }

    public function ensureCalendar(string $tenant, string $id): void
    {
        CalendarRecord::query()->firstOrCreate(['tenant_key' => $tenant, 'identifier' => $id]);
    }

    public function setCalendarStatus(string $tenant, string $id, CalendarStatus $status, ?string $reason = null): void
    {
        $updated = CalendarRecord::query()->where('tenant_key', $tenant)->where('identifier', $id)->update([
            'status' => $status->value,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);

        if ($updated === 0) {
            throw CalendarNotFound::forIdentifier($id, $tenant);
        }
    }

    private static function summary(string $id, CalendarVersionRecord $row): VersionSummary
    {
        return new VersionSummary(
            $id,
            $row->version,
            VersionStatus::from($row->status),
            $row->content_hash,
            $row->published_at?->toDateTimeImmutable(),
            $row->submitted_by,
            $row->submitted_at?->toDateTimeImmutable(),
            $row->reviewed_by,
            $row->reviewed_at?->toDateTimeImmutable(),
            $row->review_notes,
            $row->published_by,
        );
    }

    /** @return Builder<CalendarVersionRecord> */
    private function versionsOf(string $tenant, string $id): Builder
    {
        return CalendarVersionRecord::query()->whereHas('calendar', static fn (Builder $q) => $q->where('tenant_key', $tenant)->where('identifier', $id));
    }

    /** @return array<string, mixed> */
    private function versionColumns(CalendarDefinition $definition): array
    {
        $metadata = $definition->metadata->toArray();

        return [
            'status' => VersionStatus::Draft->value,
            'driver' => $definition->driver,
            'name_original' => $definition->name->original,
            'name_language' => $definition->name->language,
            'name_direction' => $definition->name->direction->value,
            'epoch_date' => $definition->epoch?->date->toString(),
            'epoch_label' => $definition->epoch?->label === null ? null : json_encode($definition->epoch->label->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'effective_from' => $definition->effective->from?->toString(),
            'effective_until' => $definition->effective->until?->toString(),
            'region' => $metadata['region'],
            'verification_status' => $metadata['verification'],
            'metadata' => array_intersect_key($metadata, array_flip(['description', 'assumptions', 'sources', 'tags', 'extra'])),
            'content_hash' => $definition->contentHash(),
            'published_at' => null,
            'parameters' => $definition->parameters,
        ];
    }

    private function writeNames(CalendarVersionRecord $version, CalendarDefinition $definition): void
    {
        foreach ($definition->names as $unit => $names) {
            foreach ($names as $sort => $name) {
                $row = CalendarNameRecord::query()->create([
                    'calendar_version_id' => $version->id,
                    'unit' => $unit,
                    'identifier' => $name->id,
                    'sort' => $sort,
                    'label_original' => $name->label->original,
                    'label_language' => $name->label->language,
                    'label_direction' => $name->label->direction->value,
                ]);
                $this->terms->write('name', $row->id, 'label', $name->label);
            }
        }
    }

    private function writeCycles(CalendarVersionRecord $version, CalendarDefinition $definition): void
    {
        foreach ($definition->cycles as $sort => $cycle) {
            $cycleRow = CycleRecord::query()->create([
                'calendar_version_id' => $version->id,
                'identifier' => $cycle->id,
                'sort' => $sort,
                'name_original' => $cycle->name->original,
                'name_language' => $cycle->name->language,
                'continuity' => $cycle->continuity->value,
                'effective_from' => $cycle->effective->from?->toString(),
                'effective_until' => $cycle->effective->until?->toString(),
            ]);
            $this->terms->write('cycle', $cycleRow->id, 'name', $cycle->name);

            $positionIds = [];

            foreach ($cycle->positions as $position) {
                $positionRow = $cycleRow->positions()->create([
                    'identifier' => $position->id,
                    'ordinal' => $position->ordinal,
                    'label_original' => $position->label->original,
                    'label_language' => $position->label->language,
                    'label_direction' => $position->label->direction->value,
                ]);
                $positionIds[$position->id] = $positionRow->id;
                $this->terms->write('position', $positionRow->id, 'label', $position->label);
            }

            $positionId = static fn (string $position, string $path): int => $positionIds[$position]
                ?? throw InvalidDefinition::single($definition->id(), "cycles.{$cycle->id}.{$path}", "names unknown position [{$position}].");

            foreach ($cycle->anchors as $anchor) {
                AnchorRecord::query()->create([
                    'cycle_id' => $cycleRow->id,
                    'cycle_position_id' => $positionId($anchor->positionId, "anchors.{$anchor->id}"),
                    'identifier' => $anchor->id,
                    'anchor_date' => $anchor->date->toString(),
                    ...$this->rangeColumns($anchor->effective),
                    'source_ids' => $anchor->sources,
                ]);
            }

            foreach ($cycle->exceptions as $exception) {
                $row = ExceptionRecord::query()->create([
                    'cycle_id' => $cycleRow->id,
                    'cycle_position_id' => $positionId($exception->positionId, "exceptions.{$exception->id}"),
                    'identifier' => $exception->id,
                    'exception_date' => $exception->date->toString(),
                    'reason_original' => $exception->reason?->original,
                    'reason_language' => $exception->reason?->language,
                    'source_ids' => $exception->sources,
                ]);

                if ($exception->reason !== null) {
                    $this->terms->write('exception', $row->id, 'reason', $exception->reason);
                }
            }
        }
    }

    /** Remove a draft's child rows and their localizations before rewriting it. */
    private function clearTree(CalendarVersionRecord $version): void
    {
        $cycles = $version->cycles()->with(['positions', 'exceptions'])->get();

        $this->terms->forget('version', [$version->id]);
        $this->terms->forget('name', self::ids($version->names()->get()));
        CalendarNameRecord::query()->where('calendar_version_id', $version->id)->delete();
        $this->terms->forget('cycle', self::ids($cycles));
        $this->terms->forget('position', self::ids($cycles->flatMap->positions));
        $this->terms->forget('exception', self::ids($cycles->flatMap->exceptions));

        CycleRecord::query()->where('calendar_version_id', $version->id)->delete();
    }

    /** @return array{effective_from: ?string, effective_until: ?string} */
    private function rangeColumns(EffectiveRange $range): array
    {
        return ['effective_from' => $range->from?->toString(), 'effective_until' => $range->until?->toString()];
    }

    /**
     * @param  list<CalendarVersionRecord>|array<int, CalendarVersionRecord>  $rows
     * @return list<CalendarDefinition>
     */
    private function hydrate(array $rows, string $tenant): array
    {
        $cycles = (new Collection($rows))->flatMap->cycles;

        $this->terms->preload([
            'version' => self::ids($rows),
            'name' => self::ids((new Collection($rows))->flatMap->names),
            'cycle' => self::ids($cycles),
            'position' => self::ids($cycles->flatMap->positions),
            'exception' => self::ids($cycles->flatMap->exceptions),
        ]);

        return array_values(array_map(fn (CalendarVersionRecord $row): CalendarDefinition => $this->factory->fromArray($this->toArray($row), $tenant), $rows));
    }

    /** @return array<string, mixed> */
    private function toArray(CalendarVersionRecord $row): array
    {
        $metadata = $row->metadata ?? [];
        $epochLabel = $row->epoch_label === null ? null : json_decode($row->epoch_label, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $row->calendar?->identifier,
            'version' => $row->version,
            'status' => $row->status,
            'published_at' => $row->published_at?->format(DATE_ATOM),
            'name' => $this->terms->read('version', $row->id, 'name', $row->name_original, $row->name_language, $row->name_direction),
            'driver' => $row->driver,
            'epoch' => $row->epoch_date === null ? null : ['date' => self::day($row->epoch_date), 'label' => $epochLabel],
            'effective' => ['from' => self::day($row->effective_from), 'until' => self::day($row->effective_until)],
            'metadata' => [...$metadata, 'region' => $row->region, 'verification' => $row->verification_status],
            'parameters' => $row->parameters ?? [],
            'names' => $row->names->groupBy('unit')->map(fn ($names) => $names->map(fn (CalendarNameRecord $n): array => [
                'id' => $n->identifier,
                'label' => $this->terms->read('name', $n->id, 'label', $n->label_original, $n->label_language, $n->label_direction),
            ])->values()->all())->all(),
            'cycles' => $row->cycles->map(fn (CycleRecord $cycle): array => [
                'id' => $cycle->identifier,
                'name' => $this->terms->read('cycle', $cycle->id, 'name', $cycle->name_original, $cycle->name_language),
                'continuity' => $cycle->continuity,
                'effective' => ['from' => self::day($cycle->effective_from), 'until' => self::day($cycle->effective_until)],
                'positions' => $cycle->positions->map(fn ($p): array => [
                    'id' => $p->identifier,
                    'ordinal' => $p->ordinal,
                    'label' => $this->terms->read('position', $p->id, 'label', $p->label_original, $p->label_language, $p->label_direction),
                ])->all(),
                'anchors' => $cycle->anchors->map(static fn (AnchorRecord $a): array => [
                    'id' => $a->identifier,
                    'date' => self::day($a->anchor_date),
                    'position' => $a->position?->identifier,
                    'effective' => ['from' => self::day($a->effective_from), 'until' => self::day($a->effective_until)],
                    'sources' => $a->source_ids ?? [],
                ])->all(),
                'exceptions' => $cycle->exceptions->map(fn (ExceptionRecord $e): array => [
                    'id' => $e->identifier,
                    'date' => self::day($e->exception_date),
                    'position' => $e->position?->identifier,
                    'reason' => $e->reason_original === null ? null : $this->terms->read('exception', $e->id, 'reason', $e->reason_original, $e->reason_language),
                    'sources' => $e->source_ids ?? [],
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @param  iterable<Record>  $rows
     * @return list<int>
     */
    private static function ids(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row->id;
        }

        return $ids;
    }

    /** Date columns come back as 'Y-m-d' or 'Y-m-d H:i:s' depending on the driver. */
    public static function day(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 10);
    }
}
