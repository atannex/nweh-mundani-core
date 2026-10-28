<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

use Atannex\NwehMundani\Core\Observances\Observance;

/**
 * Stored observances. Observances are managed separately from calendar
 * versions so a festival can be added without re-publishing the calendar.
 */
interface ObservanceRepository
{
    public function isAvailable(): bool;

    /** @return list<Observance> Observances of exactly this tenant attached to a calendar. */
    public function forCalendar(string $tenant, string $calendar): array;

    /** @return list<Observance> Every observance of exactly this tenant. */
    public function all(string $tenant): array;

    public function find(string $tenant, string $calendar, string $id): ?Observance;

    public function save(Observance $observance): void;

    public function delete(string $tenant, string $calendar, string $id): bool;
}
