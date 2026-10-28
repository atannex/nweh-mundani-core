<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $calendar_id
 * @property string $version
 * @property string $status
 * @property string $driver
 * @property string $name_original
 * @property string|null $name_language
 * @property string $name_direction
 * @property string|null $epoch_date
 * @property string|null $epoch_label
 * @property string|null $effective_from
 * @property string|null $effective_until
 * @property string|null $region
 * @property string $verification_status
 * @property array<string, mixed>|null $metadata
 * @property string $content_hash
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $reviewed_by
 * @property string|null $review_notes
 * @property string|null $published_by
 * @property array<string, scalar|null>|null $parameters
 * @property Collection<int, CalendarNameRecord> $names
 * @property CalendarRecord|null $calendar
 * @property Collection<int, CycleRecord> $cycles
 */
class CalendarVersionRecord extends Record
{
    protected $table = 'nmc_calendar_versions';

    protected $fillable = ['calendar_id', 'version', 'status', 'driver', 'name_original', 'name_language', 'name_direction', 'epoch_date', 'epoch_label', 'effective_from', 'effective_until', 'region', 'verification_status', 'metadata', 'content_hash', 'published_at', 'superseded_at', 'created_by', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_notes', 'published_by', 'parameters'];

    /** Columns that record the workflow rather than the definition's content. */
    public const array WORKFLOW_COLUMNS = ['status', 'superseded_at', 'published_at', 'published_by', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_notes', 'updated_at'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'metadata' => 'array', 'published_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    /**
     * Outside draft only workflow bookkeeping may change, and only along a
     * transition the lifecycle allows; a released version may never be
     * deleted. Checked in Eloquent's write methods, not in event listeners,
     * so Event::fake() or withoutEvents() cannot switch it off.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        $from = (string) $this->getOriginal('status');

        if ($from !== VersionStatus::Draft->value) {
            $current = VersionStatus::tryFrom($from);
            $next = VersionStatus::tryFrom($this->status);

            $allowed = $current !== null && $next !== null
                && (! $this->isDirty('status') || $current->canMoveTo($next))
                && array_diff(array_keys($this->getDirty()), self::WORKFLOW_COLUMNS) === [];

            if (! $allowed) {
                throw ImmutableVersion::forVersion($this->calendar->identifier ?? '?', (string) $this->getOriginal('version'), $from);
            }
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->getOriginal('status') !== VersionStatus::Draft->value) {
            throw ImmutableVersion::forVersion($this->calendar->identifier ?? '?', $this->version, $this->status);
        }

        parent::performDeleteOnModel();
    }

    /** @return BelongsTo<CalendarRecord, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(CalendarRecord::class, 'calendar_id');
    }

    /** @return HasMany<CalendarNameRecord, $this> */
    public function names(): HasMany
    {
        return $this->hasMany(CalendarNameRecord::class, 'calendar_version_id')->orderBy('unit')->orderBy('sort');
    }

    /** @return HasMany<CycleRecord, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(CycleRecord::class, 'calendar_version_id')->orderBy('sort');
    }
}
