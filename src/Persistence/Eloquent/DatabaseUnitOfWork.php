<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;

final readonly class DatabaseUnitOfWork implements UnitOfWork
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $connection = null,
    ) {}

    public function run(Closure $work): mixed
    {
        return $this->db->connection($this->connection)->transaction($work);
    }
}
