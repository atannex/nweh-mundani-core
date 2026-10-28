<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property int $observance_id
 * @property string $occurrence_date
 * @property string $action
 * @property string|null $rescheduled_to
 * @property string|null $reason
 * @property string|null $created_by
 */
class ObservanceOverrideRecord extends Record
{
    protected $table = 'nmc_observance_overrides';

    protected $fillable = ['observance_id', 'occurrence_date', 'action', 'rescheduled_to', 'reason', 'created_by'];
}
