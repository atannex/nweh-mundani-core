<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

use Atannex\NwehMundani\Core\Governance\Source;

interface SourceRepository
{
    public function isAvailable(): bool;

    public function find(string $tenant, string $id): ?Source;

    /** @return list<Source> */
    public function all(string $tenant): array;

    public function save(Source $source): void;
}
