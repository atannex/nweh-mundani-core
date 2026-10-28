<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Persistence\Contracts\TranslationRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\TranslationRecord;
use Throwable;

final class EloquentTranslationRepository implements TranslationRepository
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = (new TranslationRecord)->getConnection()->getSchemaBuilder()->hasTable('nmc_translations');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function forCalendar(string $tenant, string $calendar): array
    {
        $map = [];

        foreach (TranslationRecord::query()->where('tenant_key', $tenant)->where('calendar_identifier', $calendar)->get(['term_key', 'locale', 'text']) as $row) {
            $map[$row->term_key][$row->locale] = $row->text;
        }

        return $map;
    }

    public function save(string $tenant, string $calendar, string $termKey, string $locale, string $text, ?string $actor = null): void
    {
        TranslationRecord::query()->updateOrCreate(
            ['tenant_key' => $tenant, 'calendar_identifier' => $calendar, 'term_key' => $termKey, 'locale' => $locale],
            ['text' => $text, 'created_by' => $actor],
        );
    }

    public function delete(string $tenant, string $calendar, string $termKey, string $locale): bool
    {
        return TranslationRecord::query()
            ->where(['tenant_key' => $tenant, 'calendar_identifier' => $calendar, 'term_key' => $termKey, 'locale' => $locale])
            ->delete() > 0;
    }
}
