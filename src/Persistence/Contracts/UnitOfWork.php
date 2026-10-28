<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

use Closure;

/** Runs several repository writes all-or-nothing. */
interface UnitOfWork
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed;
}
