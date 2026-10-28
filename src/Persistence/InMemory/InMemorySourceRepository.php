<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;

final class InMemorySourceRepository implements SourceRepository
{
    /** @var array<string, array<string, Source>> */
    private array $store = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function find(string $tenant, string $id): ?Source
    {
        return $this->store[$tenant][$id] ?? null;
    }

    public function all(string $tenant): array
    {
        return array_values($this->store[$tenant] ?? []);
    }

    public function save(Source $source): void
    {
        $this->store[$source->tenant][$source->id] = $source;
    }
}
