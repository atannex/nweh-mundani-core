<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Cache;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Caches what is expensive to load: definitions and observance sets read
 * from the database. Calculations are not cached; the cyclic arithmetic is
 * O(1) and cheaper than a cache round trip.
 *
 * Keys include the tenant, calendar and requested version plus a
 * generation token per (tenant, calendar). Publishing bumps the token, which
 * orphans every older entry without needing cache tags (which not every
 * store supports). Cached values are plain arrays and are re-validated by
 * the factories on the way out, so a tampered cache cannot inject objects.
 */
final readonly class DefinitionCache
{
    private const array MISSING = ['__nmc_missing' => true];

    public function __construct(
        private ?Repository $store,
        private CalendarFactory $calendars,
        private ObservanceFactory $observances,
        private string $prefix = 'nmc',
        private int $ttl = 3600,
    ) {}

    public function enabled(): bool
    {
        return $this->store !== null;
    }

    /**
     * @param  callable(): ?CalendarDefinition  $load
     */
    public function definition(string $tenant, string $id, ?string $version, callable $load): ?CalendarDefinition
    {
        if ($this->store === null) {
            return $load();
        }

        $key = $this->key('def', $tenant, $id, $version ?? '@published', $this->generation('cal', $tenant, $id));
        $cached = $this->read($key);

        // Misses are cached too (as a marker), so repeated lookups of an id
        // that only exists further down the precedence chain cost nothing.
        // Every write bumps the generation, so a cached miss cannot outlive
        // the calendar being created.
        if ($cached === self::MISSING) {
            return null;
        }

        if (is_array($cached)) {
            try {
                /** @var array<string, mixed> $cached */
                return $this->calendars->fromArray($cached, $tenant);
            } catch (Throwable) {
                $this->store->forget($key);
            }
        }

        $definition = $load();
        $this->write($key, $definition === null ? self::MISSING : $definition->toArray());

        return $definition;
    }

    /**
     * @param  callable(): list<Observance>  $load
     * @return list<Observance>
     */
    public function observances(string $tenant, string $calendar, callable $load): array
    {
        if ($this->store === null) {
            return $load();
        }

        $key = $this->key('obs', $tenant, $calendar, $this->generation('obs', $tenant, $calendar));
        $cached = $this->read($key);

        if (is_array($cached)) {
            try {
                /** @var list<array<string, mixed>> $cached */
                return array_map(fn (array $row): Observance => $this->observances->fromArray($row, $tenant), $cached);
            } catch (Throwable) {
                $this->store->forget($key);
            }
        }

        $observances = $load();
        $this->write($key, array_map(static fn (Observance $o): array => $o->toArray(), $observances));

        return $observances;
    }

    /**
     * @param  callable(): array<string, array<string, string>>  $load
     * @return array<string, array<string, string>>
     */
    public function translations(string $tenant, string $calendar, callable $load): array
    {
        if ($this->store === null) {
            return $load();
        }

        $key = $this->key('tr', $tenant, $calendar, $this->generation('tr', $tenant, $calendar));
        $cached = $this->read($key);

        if (is_array($cached) && self::isTranslationMap($cached)) {
            /** @var array<string, array<string, string>> $cached */
            return $cached;
        }

        $map = $load();
        $this->write($key, $map);

        return $map;
    }

    /**
     * A small value tied to a calendar's generation (for example its
     * lifecycle status), wrapped so a cached null is distinguishable from a miss.
     *
     * @template T of scalar|null
     *
     * @param  callable(): T  $load
     * @return T
     */
    public function calendarValue(string $name, string $tenant, string $id, callable $load): mixed
    {
        if ($this->store === null) {
            return $load();
        }

        $key = $this->key('val', $name, $tenant, $id, $this->generation('cal', $tenant, $id));
        $cached = $this->read($key);

        if (is_array($cached) && array_key_exists('v', $cached) && (is_scalar($cached['v']) || $cached['v'] === null)) {
            /** @var T */
            return $cached['v'];
        }

        $value = $load();
        $this->write($key, ['v' => $value]);

        return $value;
    }

    public function forgetTranslations(string $tenant, string $calendar): void
    {
        $this->bump('tr', $tenant, $calendar);
    }

    /** The current generation token (exposed so in-process memos can key on it). */
    public function generationOf(string $kind, string $tenant, string $id): string
    {
        return $this->store === null ? '0' : $this->generation($kind, $tenant, $id);
    }

    /** @param array<mixed> $value */
    private static function isTranslationMap(array $value): bool
    {
        foreach ($value as $key => $locales) {
            if (! is_string($key) || ! is_array($locales) || array_filter($locales, 'is_string') !== $locales) {
                return false;
            }
        }

        return true;
    }

    public function forgetCalendar(string $tenant, string $id): void
    {
        $this->bump('cal', $tenant, $id);
    }

    public function forgetObservances(string $tenant, string $calendar): void
    {
        $this->bump('obs', $tenant, $calendar);
    }

    /** Exposed for tests and diagnostics. */
    public function key(string ...$parts): string
    {
        return $this->prefix.':'.$parts[0].':'.hash('sha256', json_encode(array_slice($parts, 1), JSON_THROW_ON_ERROR));
    }

    private function generation(string $kind, string $tenant, string $id): string
    {
        $value = $this->read($this->key('gen', $kind, $tenant, $id));

        return is_string($value) ? $value : '0';
    }

    private function bump(string $kind, string $tenant, string $id): void
    {
        try {
            $this->store?->forever($this->key('gen', $kind, $tenant, $id), bin2hex(random_bytes(8)));
        } catch (Throwable) {
            // A cache outage must not block a publication; entries expire by TTL.
        }
    }

    private function read(string $key): mixed
    {
        try {
            return $this->store?->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function write(string $key, mixed $value): void
    {
        try {
            $this->store?->put($key, $value, $this->ttl);
        } catch (Throwable) {
            // Caching is an optimisation; failures fall through to the loader next time.
        }
    }
}
