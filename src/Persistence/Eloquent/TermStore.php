<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\LocalizationRecord;

/**
 * Persists the parts of a Term that do not fit on the owning row
 * (alternates, translations, pronunciation, audio) in nmc_localizations,
 * and reads them back in one query per batch of owners.
 */
final class TermStore
{
    /** @var array<string, list<LocalizationRecord>> "type:id" => rows */
    private array $loaded = [];

    public function write(string $ownerType, int $ownerId, string $field, Term $term): void
    {
        $rows = [];
        $now = now();
        $row = static fn (string $kind, ?string $locale, int $sort, string $text): array => [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'field' => $field,
            'kind' => $kind, 'locale' => $locale, 'sort' => $sort, 'text' => $text,
            'created_at' => $now, 'updated_at' => $now,
        ];

        foreach ($term->alternates as $i => $alternate) {
            $rows[] = $row('alternate', null, $i, $alternate);
        }

        foreach ($term->translations as $locale => $text) {
            $rows[] = $row('translation', (string) $locale, 0, $text);
        }

        if ($term->pronunciation !== null) {
            $rows[] = $row('pronunciation', null, 0, $term->pronunciation);
        }

        if ($term->audio !== null) {
            $rows[] = $row('audio', null, 0, $term->audio);
        }

        if ($rows !== []) {
            LocalizationRecord::query()->insert($rows);
        }
    }

    /** @param list<int> $ownerIds */
    public function forget(string $ownerType, array $ownerIds): void
    {
        if ($ownerIds !== []) {
            LocalizationRecord::query()->where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->delete();
        }
    }

    /**
     * Load the rows of many owners at once (avoids one query per term).
     *
     * @param  array<string, list<int>>  $owners  type => ids
     */
    public function preload(array $owners): void
    {
        $this->loaded = [];

        foreach ($owners as $type => $ids) {
            if ($ids === []) {
                continue;
            }

            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                $rows = LocalizationRecord::query()->where('owner_type', $type)->whereIn('owner_id', $chunk)->orderBy('sort')->orderBy('id')->get();

                foreach ($rows as $row) {
                    $this->loaded["{$row->owner_type}:{$row->owner_id}"][] = $row;
                }
            }
        }
    }

    /** @return array<string, mixed> Term array form for CalendarFactory / ObservanceFactory. */
    public function read(string $ownerType, int $ownerId, string $field, string $original, ?string $language = null, string $direction = 'ltr'): array
    {
        $term = ['original' => $original, 'language' => $language, 'direction' => $direction, 'alternates' => [], 'translations' => []];

        foreach ($this->loaded["{$ownerType}:{$ownerId}"] ?? [] as $row) {
            if ($row->field !== $field) {
                continue;
            }

            match ($row->kind) {
                'alternate' => $term['alternates'][] = $row->text,
                'translation' => $term['translations'][(string) $row->locale] = $row->text,
                'pronunciation' => $term['pronunciation'] = $row->text,
                'audio' => $term['audio'] = $row->text,
                default => null,
            };
        }

        return $term;
    }
}
