<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Closure;

/**
 * For in-memory storage: runs the work directly. In-memory repositories
 * check every precondition before writing, so a failure leaves them unchanged.
 */
final class ImmediateUnitOfWork implements UnitOfWork
{
    public function run(Closure $work): mixed
    {
        return $work();
    }
}
