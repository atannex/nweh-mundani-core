<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Localization\Term;
use InvalidArgumentException;

/**
 * An immutable description of a calendar, independent of how it is computed.
 *
 * The driver key names the algorithm; everything else is data the driver
 * reads. Build one with CalendarFactory from arrays, config or database rows.
 */
final readonly class CalendarDefinition
{
    /** @var array<string, CalendarCycle> */
    private array $cyclesById;

    public const int MAX_PARAMETERS = 20;

    /** @var array<string, scalar|null> Driver settings (e.g. ['rule' => 'julian']); the driver validates their meaning. */
    public array $parameters;

    /** @var array<string, list<CalendarName>> Names of unit values per unit (e.g. 'month' => [...]). */
    public array $names;

    /**
     * @param  list<CalendarCycle>  $cycles
     * @param  array<array-key, mixed>  $parameters  checked here: identifier keys, scalar values
     * @param  array<array-key, mixed>  $names  checked here: identifier units, lists of distinct CalendarName
     */
    public function __construct(
        public CalendarIdentity $identity,
        public CalendarVersion $version,
        public Term $name,
        public string $driver,
        public array $cycles = [],
        public ?CalendarEpoch $epoch = null,
        public EffectiveRange $effective = new EffectiveRange,
        public CalendarMetadata $metadata = new CalendarMetadata,
        array $parameters = [],
        array $names = [],
    ) {
        Identifier::assert($driver, 'driver key');

        if (count($parameters) > self::MAX_PARAMETERS) {
            throw new InvalidArgumentException("Calendar [{$identity->id}] has more than ".self::MAX_PARAMETERS.' driver parameters.');
        }

        $checkedParameters = [];

        foreach ($parameters as $key => $value) {
            Identifier::assert((string) $key, 'parameter key');

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Calendar [{$identity->id}] parameter [{$key}] must be a scalar.");
            }

            $checkedParameters[(string) $key] = $value;
        }

        $checkedNames = [];

        foreach ($names as $unit => $list) {
            Identifier::assert((string) $unit, 'unit identifier');
            $seen = [];

            if (! is_array($list) || ! array_is_list($list)) {
                throw new InvalidArgumentException("Calendar [{$identity->id}] names for [{$unit}] must be a list.");
            }

            foreach ($list as $name) {
                if (! $name instanceof CalendarName || isset($seen[$name->id])) {
                    throw new InvalidArgumentException("Calendar [{$identity->id}] names for [{$unit}] must be distinct CalendarName values.");
                }

                $seen[$name->id] = true;
                $checkedNames[(string) $unit][] = $name;
            }
        }

        $this->parameters = $checkedParameters;
        $this->names = $checkedNames;

        $byId = [];

        foreach ($cycles as $cycle) {
            if (isset($byId[$cycle->id])) {
                throw new InvalidArgumentException("Calendar [{$identity->id}] defines cycle [{$cycle->id}] twice.");
            }

            $byId[$cycle->id] = $cycle;
        }

        $this->cyclesById = $byId;
    }

    public function id(): string
    {
        return $this->identity->id;
    }

    public function tenant(): string
    {
        return $this->identity->tenant;
    }

    public function cycle(string $id): ?CalendarCycle
    {
        return $this->cyclesById[$id] ?? null;
    }

    public function primaryCycle(): ?CalendarCycle
    {
        return $this->cycles[0] ?? null;
    }

    public function parameter(string $key, string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        return $this->parameters[$key] ?? $default;
    }

    public function nameOf(string $unit, string $id): ?CalendarName
    {
        foreach ($this->names[$unit] ?? [] as $name) {
            if ($name->id === $id) {
                return $name;
            }
        }

        return null;
    }

    public function withVersion(CalendarVersion $version): self
    {
        return new self($this->identity, $version, $this->name, $this->driver, $this->cycles, $this->epoch, $this->effective, $this->metadata, $this->parameters, $this->names);
    }

    public function withIdentity(CalendarIdentity $identity): self
    {
        return new self($identity, $this->version, $this->name, $this->driver, $this->cycles, $this->epoch, $this->effective, $this->metadata, $this->parameters, $this->names);
    }

    /**
     * Canonical array form; CalendarFactory::fromArray() reads it back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->identity->id,
            'tenant' => $this->identity->tenant,
            'version' => $this->version->number,
            'status' => $this->version->status->value,
            'published_at' => $this->version->publishedAt?->format(DATE_ATOM),
            'name' => $this->name->toArray(),
            'driver' => $this->driver,
            'epoch' => $this->epoch?->toArray(),
            'effective' => $this->effective->toArray(),
            'metadata' => $this->metadata->toArray(),
            'cycles' => array_map(static fn (CalendarCycle $c): array => $c->toArray(), $this->cycles),
            'parameters' => self::sorted($this->parameters),
            'names' => self::sorted(array_map(static fn (array $list): array => array_map(static fn (CalendarName $n): array => $n->toArray(), $list), $this->names)),
        ];
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $map
     * @return array<string, T>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * Hash of the content alone (not status, publication time or tenant),
     * used to detect whether two registrations really describe the same thing.
     */
    public function contentHash(): string
    {
        $content = $this->toArray();
        unset($content['tenant'], $content['status'], $content['published_at']);

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
