<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 *
 * Base for the package's tables: honours nweh-mundani.persistence.connection.
 * These models are a persistence detail; the engine never sees them.
 */
abstract class Record extends Model
{
    /**
     * Every model lists its own $fillable columns; nothing else can be
     * mass-assigned, and the primary key never can.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function getConnectionName(): ?string
    {
        $connection = config('nweh-mundani.persistence.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }
}
