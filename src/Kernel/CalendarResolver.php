<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\CalendarUnavailable;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;

/**
 * Finds the definition a context means by an identifier.
 *
 * Within one scope, stored definitions beat code-registered ones (so an
 * administrator can override a packaged calendar through the database).
 * Between scopes, the tenancy policy decides:
 *
 *  - allow_shadowing = false (default): the global definition wins; a tenant
 *    definition with the same id is ignored (and refused on write).
 *    Order: stored global, code global, stored tenant, code tenant.
 *  - allow_shadowing = true: the tenant's own definition wins.
 *    Order: stored tenant, stored global, code tenant, code global.
 *
 * No context ever sees another tenant's calendars. A stored calendar that
 * is suspended or archived stops resolution (CalendarUnavailable): it does
 * not quietly fall through to a code-registered calendar of the same id.
 */
final readonly class CalendarResolver
{
    public function __construct(
        private CalendarRegistry $registry,
        private ?CalendarRepository $repository,
        private DefinitionCache $cache,
        private bool $allowShadowing = false,
    ) {}

    /** @throws CalendarNotFound|CalendarUnavailable */
    public function resolve(CalendarContext $context, string $id, ?string $version = null, bool $includeInactive = false): CalendarDefinition
    {
        foreach ($this->order($context) as [$source, $tenant]) {
            if ($source === 'stored') {
                if (! $this->storeReady()) {
                    continue;
                }

                $status = $this->status($tenant, $id);

                if ($status !== null && $status !== CalendarStatus::Active && ! $includeInactive) {
                    throw CalendarUnavailable::because($id, $status->value);
                }

                $stored = $this->cache->definition($tenant, $id, $version, fn (): ?CalendarDefinition => $version === null
                    ? $this->repository?->findPublished($tenant, $id)
                    : $this->repository?->findVersion($tenant, $id, $version));

                if ($stored !== null) {
                    return $stored;
                }

                continue;
            }

            if (($registered = $this->registry->find($tenant, $id, $version)) !== null) {
                return $registered;
            }
        }

        throw CalendarNotFound::forIdentifier($id, $context->tenant, $version);
    }

    public function exists(CalendarContext $context, string $id, bool $includeInactive = false): bool
    {
        try {
            $this->resolve($context, $id, includeInactive: $includeInactive);

            return true;
        } catch (CalendarNotFound|CalendarUnavailable) {
            return false;
        }
    }

    /**
     * Status of the calendar a context resolves to: the stored status, or
     * Active for code-registered calendars. Null when nothing resolves.
     */
    public function statusOf(CalendarContext $context, string $id): ?CalendarStatus
    {
        foreach ($this->order($context) as [$source, $tenant]) {
            if ($source === 'stored' && $this->storeReady() && ($status = $this->status($tenant, $id)) !== null) {
                return $status;
            }

            if ($source === 'code' && $this->registry->find($tenant, $id) !== null) {
                return CalendarStatus::Active;
            }
        }

        return null;
    }

    /**
     * The active definition of every calendar visible to the context, one per
     * identifier, with the same precedence as resolve(). Sorted by id.
     *
     * @return array<string, CalendarDefinition>
     */
    public function all(CalendarContext $context, bool $includeInactive = false): array
    {
        $found = [];
        $blocked = [];

        foreach ($this->order($context) as [$source, $tenant]) {
            if ($source === 'stored') {
                if (! $this->storeReady()) {
                    continue;
                }

                foreach ($this->repository?->calendarStatuses($tenant) ?? [] as $id => $status) {
                    if ($status !== CalendarStatus::Active && ! $includeInactive && ! isset($found[$id])) {
                        $blocked[$id] = true;
                    }
                }

                foreach ($this->repository?->published($tenant) ?? [] as $definition) {
                    if (! isset($blocked[$definition->id()])) {
                        $found[$definition->id()] ??= $definition;
                    }
                }

                continue;
            }

            foreach ($this->registry->active($tenant) as $definition) {
                if (! isset($blocked[$definition->id()])) {
                    $found[$definition->id()] ??= $definition;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** Whether trusted code registered this id in exactly this tenant scope. */
    public function isCodeRegistered(string $tenant, string $id): bool
    {
        return $this->registry->find($tenant, $id) !== null;
    }

    public function allowsShadowing(): bool
    {
        return $this->allowShadowing;
    }

    /**
     * Whether a global calendar (stored or code-registered, in any status)
     * already uses this id.
     */
    public function globalExists(string $id): bool
    {
        return ($this->storeReady() && $this->repository?->calendarStatus(TenantKey::GLOBAL, $id) !== null)
            || $this->registry->find(TenantKey::GLOBAL, $id) !== null;
    }

    /** @return list<array{0: 'stored'|'code', 1: string}> */
    private function order(CalendarContext $context): array
    {
        if ($context->isGlobal()) {
            return [['stored', TenantKey::GLOBAL], ['code', TenantKey::GLOBAL]];
        }

        return $this->allowShadowing
            ? [['stored', $context->tenant], ['stored', TenantKey::GLOBAL], ['code', $context->tenant], ['code', TenantKey::GLOBAL]]
            : [['stored', TenantKey::GLOBAL], ['code', TenantKey::GLOBAL], ['stored', $context->tenant], ['code', $context->tenant]];
    }

    private function status(string $tenant, string $id): ?CalendarStatus
    {
        $value = $this->cache->calendarValue('status', $tenant, $id, fn (): ?string => $this->repository?->calendarStatus($tenant, $id)?->value);

        return is_string($value) ? CalendarStatus::tryFrom($value) : null;
    }

    private function storeReady(): bool
    {
        return $this->repository !== null && $this->repository->isAvailable();
    }
}
