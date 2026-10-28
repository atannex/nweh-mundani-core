<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $identifier
 * @property string $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $status_changed_at
 */
class CalendarRecord extends Record
{
    protected $table = 'nmc_calendars';

    protected $fillable = ['tenant_key', 'identifier', 'status', 'status_reason', 'status_changed_at'];

    protected function casts(): array
    {
        return ['status_changed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CalendarVersionRecord, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CalendarVersionRecord::class, 'calendar_id');
    }
}
