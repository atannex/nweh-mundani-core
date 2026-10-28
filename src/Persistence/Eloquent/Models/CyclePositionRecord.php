<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cycle_id
 * @property string $identifier
 * @property int $ordinal
 * @property string $label_original
 * @property string|null $label_language
 * @property string $label_direction
 */
class CyclePositionRecord extends Record
{
    use FreezesWithVersion;

    protected $table = 'nmc_cycle_positions';

    protected $fillable = ['cycle_id', 'identifier', 'ordinal', 'label_original', 'label_language', 'label_direction'];

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
}
