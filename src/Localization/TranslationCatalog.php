<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Persistence\Contracts\TranslationRepository;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use Closure;

/**
 * Serves translations registered through TranslationManagementService.
 * The context's own tenant is consulted before global ones; another
 * tenant's entries are never read.
 *
 * Freshness: the map for a (tenant, calendar) is read from the shared cache
 * under a generation token that every write replaces, so all processes see
 * a change on their next lookup. The in-process memo on top of it lives in a
 * request-scoped TranslationMemo, so even with caching disabled a
 * long-running worker reloads translations for every request or job.
 */
final readonly class TranslationCatalog implements ContextualLocalizationProvider
{
    /** @param Closure(): TranslationMemo $memo */
    public function __construct(
        private TranslationRepository $repository,
        private DefinitionCache $cache,
        private Closure $memo,
    ) {}

    public function translateFor(CalendarContext $context, Term $term, string $key, string $locale): ?string
    {
        $calendar = self::calendarOf($key);

        if ($calendar === null || ! $this->repository->isAvailable()) {
            return null;
        }

        $normalised = str_replace('_', '-', $locale);
        $candidates = array_unique([$locale, $normalised, explode('-', $normalised)[0]]);

        foreach ($this->scopes($context) as $tenant) {
            $map = $this->map($tenant, $calendar);

            foreach ($candidates as $candidate) {
                if (isset($map[$key][$candidate])) {
                    return $map[$key][$candidate];
                }
            }
        }

        return null;
    }

    /** @return array<string, array<string, string>> */
    public function map(string $tenant, string $calendar): array
    {
        $memo = ($this->memo)();
        $memoKey = $tenant.'|'.$calendar.'|'.$this->cache->generationOf('tr', $tenant, $calendar);

        return $memo->maps[$memoKey] ??= $this->cache->translations($tenant, $calendar, fn (): array => $this->repository->forCalendar($tenant, $calendar));
    }

    public function forget(string $tenant, string $calendar): void
    {
        $this->cache->forgetTranslations($tenant, $calendar);
        ($this->memo)()->forget($tenant.'|'.$calendar.'|');
    }

    public static function calendarOf(string $key): ?string
    {
        return preg_match('/^calendar\.([a-z0-9]+(?:[-_.][a-z0-9]+)*?)\.(?:name|cycle|observance|unit)(?:\.|$)/', $key, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    private function scopes(CalendarContext $context): array
    {
        return $context->isGlobal() ? [TenantKey::GLOBAL] : [$context->tenant, TenantKey::GLOBAL];
    }
}
