<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;

/**
 * Reads observance candidates from a repository: the context tenant's own,
 * then global ones. Never another tenant's.
 */
final readonly class RepositoryObservanceResolver implements ObservanceResolver
{
    public function __construct(
        private ObservanceRepository $repository,
        private ?DefinitionCache $cache = null,
    ) {}

    public function candidates(CalendarContext $context, string $calendar): iterable
    {
        if (! $this->repository->isAvailable()) {
            return [];
        }

        $scopes = $context->isGlobal() ? [TenantKey::GLOBAL] : [$context->tenant, TenantKey::GLOBAL];
        $found = [];

        foreach ($scopes as $tenant) {
            $load = fn (): array => $this->repository->forCalendar($tenant, $calendar);
            array_push($found, ...($this->cache?->observances($tenant, $calendar, $load) ?? $load()));
        }

        return $found;
    }
}
