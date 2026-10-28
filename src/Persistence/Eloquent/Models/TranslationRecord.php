<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

/**
 * @property int $id
 * @property string $tenant_key
 * @property string $calendar_identifier
 * @property string $term_key
 * @property string $locale
 * @property string $text
 * @property string|null $created_by
 */
class TranslationRecord extends Record
{
    protected $table = 'nmc_translations';

    protected $fillable = ['tenant_key', 'calendar_identifier', 'term_key', 'locale', 'text', 'created_by'];
}
