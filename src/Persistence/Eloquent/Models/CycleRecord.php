<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $calendar_version_id
 * @property string $identifier
 * @property int $sort
 * @property string $name_original
 * @property string|null $name_language
 * @property string $continuity
 * @property string|null $effective_from
 * @property string|null $effective_until
 * @property Collection<int, CyclePositionRecord> $positions
 * @property Collection<int, AnchorRecord> $anchors
 * @property Collection<int, ExceptionRecord> $exceptions
 */
class CycleRecord extends Record
{
    use FreezesWithVersion;

    protected $table = 'nmc_cycles';

    protected $fillable = ['calendar_version_id', 'identifier', 'sort', 'name_original', 'name_language', 'continuity', 'effective_from', 'effective_until'];

    public function owningVersion(): ?CalendarVersionRecord
    {
        return CalendarVersionRecord::query()->find($this->calendar_version_id);
    }

    /** @return BelongsTo<CalendarVersionRecord, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CalendarVersionRecord::class, 'calendar_version_id');
    }

    /** @return HasMany<CyclePositionRecord, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(CyclePositionRecord::class, 'cycle_id')->orderBy('ordinal');
    }

    /** @return HasMany<AnchorRecord, $this> */
    public function anchors(): HasMany
    {
        return $this->hasMany(AnchorRecord::class, 'cycle_id')->orderBy('id');
    }

    /** @return HasMany<ExceptionRecord, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ExceptionRecord::class, 'cycle_id')->orderBy('exception_date');
    }
}
