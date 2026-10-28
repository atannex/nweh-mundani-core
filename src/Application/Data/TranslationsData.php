<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * Every translatable term of a calendar with its stable key, the exact
 * original, translations carried by the version itself, and translations
 * registered afterwards (which take precedence when rendering).
 */
final readonly class TranslationsData extends Data
{
    /** @param list<array{key: string, original: string, language: ?string, alternates: list<string>, version: array<string, string>, registered: array<string, string>}> $terms */
    public function __construct(
        public string $calendar,
        public string $version,
        public array $terms,
    ) {}

    /** @return list<string> */
    public function locales(): array
    {
        $locales = [];

        foreach ($this->terms as $term) {
            array_push($locales, ...array_keys($term['version']), ...array_keys($term['registered']));
        }

        $locales = array_values(array_unique(array_map('strval', $locales)));
        sort($locales);

        return $locales;
    }

    public function toArray(): array
    {
        return ['calendar' => $this->calendar, 'version' => $this->version, 'locales' => $this->locales(), 'terms' => $this->terms];
    }
}
