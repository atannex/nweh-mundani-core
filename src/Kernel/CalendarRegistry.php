<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\DuplicateCalendar;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Closure;

/**
 * Calendars registered by trusted code (config, service providers,
 * definition providers). They are published, immutable and never written
 * to the database. Registration is idempotent for identical content and
 * refused for conflicting content under the same identifier and version.
 *
 * Deferred registrations (closures) run on first read, so booting the
 * application does not build every definition.
 */
final class CalendarRegistry
{
    /** @var array<string, array<string, CalendarDefinition>> identity key => version => definition */
    private array $definitions = [];

    /** @var list<Closure(self): void> */
    private array $deferred = [];

    /** @param array<string, string> $pins calendar id => version to treat as active */
    public function __construct(
        private readonly CalendarValidator $validator,
        private readonly array $pins = [],
    ) {}

    /**
     * @throws DuplicateCalendar|InvalidDefinition
     */
    public function register(CalendarDefinition $definition): CalendarDefinition
    {
        if ($definition->version->status !== VersionStatus::Published) {
            throw InvalidDefinition::single($definition->id(), 'status', 'Only published definitions can be registered in code; manage drafts through CalendarAdministration.');
        }

        $this->validator->validate($definition)->throwIfFailed();

        $key = $definition->identity->key();
        $existing = $this->definitions[$key][$definition->version->number] ?? null;

        if ($existing !== null) {
            if ($existing->contentHash() !== $definition->contentHash()) {
                throw DuplicateCalendar::conflicting($definition->id(), $definition->version->number);
            }

            return $existing;
        }

        return $this->definitions[$key][$definition->version->number] = $definition;
    }

    /** @param Closure(self): void $registration */
    public function defer(Closure $registration): void
    {
        $this->deferred[] = $registration;
    }

    public function find(string $tenant, string $id, ?string $version = null): ?CalendarDefinition
    {
        $this->flush();
        $versions = $this->definitions["{$tenant}|{$id}"] ?? [];

        if ($versions === []) {
            return null;
        }

        if ($version !== null) {
            return $versions[$version] ?? null;
        }

        if (isset($this->pins[$id], $versions[$this->pins[$id]])) {
            return $versions[$this->pins[$id]];
        }

        uksort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions[array_key_last($versions)];
    }

    /** @return list<CalendarDefinition> The active version of each calendar of exactly this tenant. */
    public function active(string $tenant): array
    {
        $this->flush();
        $found = [];

        foreach (array_keys($this->definitions) as $key) {
            [$owner, $id] = explode('|', (string) $key, 2);

            if ($owner === $tenant && ($definition = $this->find($tenant, $id)) !== null) {
                $found[] = $definition;
            }
        }

        return $found;
    }

    /** @return list<string> Every version registered for a calendar, ascending. */
    public function versions(string $tenant, string $id): array
    {
        $this->flush();
        $versions = array_map('strval', array_keys($this->definitions["{$tenant}|{$id}"] ?? []));
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /** Run deferred registrations now (normally they run on first read). */
    public function boot(): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        while ($this->deferred !== []) {
            $registration = array_shift($this->deferred);
            $registration($this);
        }
    }
}
