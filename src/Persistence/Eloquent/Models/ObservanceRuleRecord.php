<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property int $observance_id
 * @property int $sort
 * @property string $type
 * @property array<string, mixed> $parameters
 * @property int $priority
 * @property string|null $effective_from
 * @property string|null $effective_until
 */
class ObservanceRuleRecord extends Record
{
    protected $table = 'nmc_observance_rules';

    protected $fillable = ['observance_id', 'sort', 'type', 'parameters', 'priority', 'effective_from', 'effective_until'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'priority' => 'integer'];
    }
}
