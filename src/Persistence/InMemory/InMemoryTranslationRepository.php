<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Persistence\Contracts\TranslationRepository;

final class InMemoryTranslationRepository implements TranslationRepository
{
    /** @var array<string, array<string, array<string, array<string, string>>>> tenant => calendar => key => locale => text */
    private array $store = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function forCalendar(string $tenant, string $calendar): array
    {
        return $this->store[$tenant][$calendar] ?? [];
    }

    public function save(string $tenant, string $calendar, string $termKey, string $locale, string $text, ?string $actor = null): void
    {
        $this->store[$tenant][$calendar][$termKey][$locale] = $text;
    }

    public function delete(string $tenant, string $calendar, string $termKey, string $locale): bool
    {
        if (! isset($this->store[$tenant][$calendar][$termKey][$locale])) {
            return false;
        }

        unset($this->store[$tenant][$calendar][$termKey][$locale]);

        return true;
    }
}
