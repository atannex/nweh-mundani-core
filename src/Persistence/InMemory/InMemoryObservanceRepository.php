<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;

final class InMemoryObservanceRepository implements ObservanceRepository
{
    /** @var array<string, array<string, array<string, Observance>>> tenant => calendar => id */
    private array $store = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function forCalendar(string $tenant, string $calendar): array
    {
        return array_values($this->store[$tenant][$calendar] ?? []);
    }

    public function all(string $tenant): array
    {
        return array_merge(...array_values(array_map('array_values', $this->store[$tenant] ?? [])));
    }

    public function find(string $tenant, string $calendar, string $id): ?Observance
    {
        return $this->store[$tenant][$calendar][$id] ?? null;
    }

    public function save(Observance $observance): void
    {
        $this->store[$observance->tenant][$observance->calendar][$observance->id] = $observance;
    }

    public function delete(string $tenant, string $calendar, string $id): bool
    {
        if (! isset($this->store[$tenant][$calendar][$id])) {
            return false;
        }

        unset($this->store[$tenant][$calendar][$id]);

        return true;
    }
}
