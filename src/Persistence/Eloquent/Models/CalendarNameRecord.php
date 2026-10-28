<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property int $calendar_version_id
 * @property string $unit
 * @property string $identifier
 * @property int $sort
 * @property string $label_original
 * @property string|null $label_language
 * @property string $label_direction
 */
class CalendarNameRecord extends Record
{
    use FreezesWithVersion;

    protected $table = 'nmc_calendar_names';

    protected $fillable = ['calendar_version_id', 'unit', 'identifier', 'sort', 'label_original', 'label_language', 'label_direction'];

    public function owningVersion(): ?CalendarVersionRecord
    {
        return CalendarVersionRecord::query()->find($this->calendar_version_id);
    }
}
