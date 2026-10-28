<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $subject_type
 * @property string $subject_key
 * @property string $status
 * @property string|null $actor
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 */
class VerificationRecord extends Record
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'nmc_verifications';

    protected $fillable = ['tenant_key', 'subject_type', 'subject_key', 'status', 'actor', 'notes', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
