<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

/**
 * Translations registered after publication, keyed by stable term paths
 * (calendar.<id>.name, calendar.<id>.cycle.<cycle>.position.<position>,
 * calendar.<id>.observance.<observance>.name). They render labels without
 * touching the authoritative original or the version's content hash.
 */
interface TranslationRepository
{
    public function isAvailable(): bool;

    /** @return array<string, array<string, string>> term key => locale => text, for exactly this tenant. */
    public function forCalendar(string $tenant, string $calendar): array;

    public function save(string $tenant, string $calendar, string $termKey, string $locale, string $text, ?string $actor = null): void;

    public function delete(string $tenant, string $calendar, string $termKey, string $locale): bool;
}
