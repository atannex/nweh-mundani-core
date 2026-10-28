<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarRegistry;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemorySourceRepository;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;

/**
 * Looks sources up with the same precedence as calendars: stored before
 * code-registered, the context's tenant before global.
 */
final readonly class SourceCatalog
{
    public function __construct(
        private SourceRepository $stored,
        private InMemorySourceRepository $static,
        private CalendarRegistry $registry,
    ) {}

    public function find(CalendarContext $context, string $id): ?Source
    {
        $this->registry->boot();
        $scopes = $context->isGlobal() ? [TenantKey::GLOBAL] : [$context->tenant, TenantKey::GLOBAL];

        foreach ([$this->stored, $this->static] as $repository) {
            if (! $repository->isAvailable()) {
                continue;
            }

            foreach ($scopes as $tenant) {
                if (($source = $repository->find($tenant, $id)) !== null) {
                    return $source;
                }
            }
        }

        return null;
    }
}
