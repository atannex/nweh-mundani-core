<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $calendar_identifier
 * @property string $identifier
 * @property string $category
 * @property string $name_original
 * @property string|null $name_language
 * @property string|null $description_original
 * @property string|null $description_language
 * @property string $visibility
 * @property string $verification_status
 * @property int $priority
 * @property list<string>|null $source_ids
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property Collection<int, ObservanceOverrideRecord> $overrides
 * @property Collection<int, ObservanceRuleRecord> $rules
 */
class ObservanceRecord extends Record
{
    protected $table = 'nmc_observances';

    protected $fillable = ['tenant_key', 'calendar_identifier', 'identifier', 'category', 'name_original', 'name_language', 'description_original', 'description_language', 'visibility', 'verification_status', 'priority', 'source_ids', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['source_ids' => 'array', 'priority' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ObservanceOverrideRecord, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(ObservanceOverrideRecord::class, 'observance_id')->orderBy('occurrence_date');
    }

    /** @return HasMany<ObservanceRuleRecord, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ObservanceRuleRecord::class, 'observance_id')->orderBy('sort');
    }
}
