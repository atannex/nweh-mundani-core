<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $subject_type
 * @property string $subject_key
 * @property string $action
 * @property string|null $actor
 * @property array<string, scalar|null>|null $changes
 * @property CarbonImmutable $created_at
 */
class RevisionLogRecord extends Record
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'nmc_revision_logs';

    protected $fillable = ['tenant_key', 'subject_type', 'subject_key', 'action', 'actor', 'changes', 'created_at'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
