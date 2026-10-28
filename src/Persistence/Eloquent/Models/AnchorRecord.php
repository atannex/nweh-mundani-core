<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cycle_id
 * @property int $cycle_position_id
 * @property string $identifier
 * @property string $anchor_date
 * @property string|null $effective_from
 * @property string|null $effective_until
 * @property list<string>|null $source_ids
 * @property CyclePositionRecord|null $position
 */
class AnchorRecord extends Record
{
    use FreezesWithVersion;

    protected $table = 'nmc_calendar_anchors';

    protected $fillable = ['cycle_id', 'cycle_position_id', 'identifier', 'anchor_date', 'effective_from', 'effective_until', 'source_ids'];

    protected function casts(): array
    {
        return ['source_ids' => 'array'];
    }

    public function owningVersion(): ?CalendarVersionRecord
    {
        $cycle = CycleRecord::query()->find($this->cycle_id);

        return $cycle === null ? null : $cycle->owningVersion();
    }

    /** @return BelongsTo<CycleRecord, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CycleRecord::class, 'cycle_id');
    }

    /** @return BelongsTo<CyclePositionRecord, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(CyclePositionRecord::class, 'cycle_position_id');
    }
}
