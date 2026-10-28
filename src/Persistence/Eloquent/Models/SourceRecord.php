<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $identifier
 * @property string $kind
 * @property string $title
 * @property string|null $citation
 * @property string|null $custodian
 * @property string|null $url
 * @property string|null $recorded_on
 * @property string $status
 * @property string|null $notes
 * @property string $visibility
 */
class SourceRecord extends Record
{
    protected $table = 'nmc_sources';

    protected $fillable = ['tenant_key', 'identifier', 'kind', 'title', 'citation', 'custodian', 'url', 'recorded_on', 'status', 'notes', 'visibility'];
}
