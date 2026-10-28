<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $field
 * @property string $kind
 * @property string|null $locale
 * @property int $sort
 * @property string $text
 */
class LocalizationRecord extends Record
{
    protected $table = 'nmc_localizations';

    protected $fillable = ['owner_type', 'owner_id', 'field', 'kind', 'locale', 'sort', 'text'];
}
