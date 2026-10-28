<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Audit rows are written once and never changed or deleted. Enforced in
 * Eloquent's write methods, so faking or muting events cannot disable it.
 */
trait AppendOnly
{
    /** @param Builder<static> $query */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException(static::class.' rows are append-only.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(static::class.' rows are append-only.');
    }
}
