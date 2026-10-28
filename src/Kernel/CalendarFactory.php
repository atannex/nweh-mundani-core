<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\Assumption;
use Atannex\NwehMundani\Core\Domain\CalendarAnchor;
use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarEpoch;
use Atannex\NwehMundani\Core\Domain\CalendarException;
use Atannex\NwehMundani\Core\Domain\CalendarIdentity;
use Atannex\NwehMundani\Core\Domain\CalendarMetadata;
use Atannex\NwehMundani\Core\Domain\CalendarName;
use Atannex\NwehMundani\Core\Domain\CalendarVersion;
use Atannex\NwehMundani\Core\Domain\CycleContinuity;
use Atannex\NwehMundani\Core\Domain\CyclePosition;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Drivers\Cyclic\CyclicDriver;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The single gate from untyped data (config arrays, fixtures, database
 * rows, JSON) into validated domain objects. Every structural problem is
 * collected and reported together in one InvalidDefinition.
 */
final class CalendarFactory
{
    /** @var list<Violation> */
    private array $violations = [];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidDefinition
     */
    public function fromArray(array $data, ?string $tenant = null): CalendarDefinition
    {
        $this->violations = [];
        $subject = is_string($data['id'] ?? null) ? $data['id'] : '(no id)';

        $identity = $this->attempt('id', fn (): CalendarIdentity => new CalendarIdentity(
            $this->string($data, 'id'),
            $tenant ?? (is_string($data['tenant'] ?? null) ? $data['tenant'] : null),
        ));

        $version = $this->attempt('version', fn (): CalendarVersion => new CalendarVersion(
            is_string($data['version'] ?? null) ? $data['version'] : '1.0.0',
            VersionStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : 'published') ?? throw new InvalidArgumentException('status must be draft, published or superseded.'),
            is_string($data['published_at'] ?? null) ? new DateTimeImmutable($data['published_at']) : null,
        ));

        $name = $this->attempt('name', fn (): Term => $this->term($data['name'] ?? null) ?? throw new InvalidArgumentException('is required.'));
        $driver = is_string($data['driver'] ?? null) ? $data['driver'] : CyclicDriver::KEY;
        $epoch = $this->attempt('epoch', fn (): ?CalendarEpoch => $this->epoch($data['epoch'] ?? null));
        $effective = $this->attempt('effective', fn () => RuleRegistry::range($data['effective'] ?? null));
        $metadata = $this->attempt('metadata', fn (): CalendarMetadata => $this->metadata($data['metadata'] ?? []));

        $cycles = [];
        $rawCycles = $data['cycles'] ?? [];

        if (! is_array($rawCycles) || ! array_is_list($rawCycles)) {
            $this->violations[] = new Violation('cycles', 'must be a list.');
            $rawCycles = [];
        }

        foreach ($rawCycles as $i => $cycle) {
            $path = 'cycles.'.(is_array($cycle) && is_string($cycle['id'] ?? null) ? $cycle['id'] : $i);
            $built = is_array($cycle) ? $this->cycle($cycle, $path) : null;

            if ($built !== null) {
                $cycles[] = $built;
            } elseif (! is_array($cycle)) {
                $this->violations[] = new Violation($path, 'must be an object.');
            }
        }

        if ($this->violations !== [] || $identity === null || $version === null || $name === null || $effective === null || $metadata === null) {
            throw new InvalidDefinition($subject, $this->violations);
        }

        $parameters = $this->attempt('parameters', fn (): array => $this->parameters($data['parameters'] ?? []));
        $names = $this->attempt('names', fn (): array => $this->names($data['names'] ?? []));

        if ($parameters === null || $names === null) {
            throw new InvalidDefinition($subject, $this->violations);
        }

        $definition = $this->attempt('', fn (): CalendarDefinition => new CalendarDefinition($identity, $version, $name, $driver, $cycles, $epoch, $effective, $metadata, $parameters, $names));

        if ($definition === null) {
            throw new InvalidDefinition($subject, $this->violations);
        }

        return $definition;
    }

    /** @param array<mixed> $data */
    private function cycle(array $data, string $path): ?CalendarCycle
    {
        $before = count($this->violations);
        $positions = [];

        foreach ($this->list($data, 'positions', $path) as $i => $position) {
            $positions[] = $this->attempt("{$path}.positions.{$i}", function () use ($position, $i): CyclePosition {
                if (! is_array($position)) {
                    throw new InvalidArgumentException('must be an object with id and label.');
                }

                if (isset($position['ordinal']) && $position['ordinal'] !== $i + 1) {
                    throw new InvalidArgumentException('ordinal '.(is_scalar($position['ordinal']) ? $position['ordinal'] : '?').' does not match its place in the list ('.($i + 1).').');
                }

                return new CyclePosition($this->string($position, 'id'), $i + 1, $this->term($position['label'] ?? null) ?? throw new InvalidArgumentException('label is required.'));
            });
        }

        if (isset($data['length']) && $data['length'] !== count($positions)) {
            $this->violations[] = new Violation("{$path}.length", 'declares '.(is_scalar($data['length']) ? $data['length'] : '?').' but '.count($positions).' positions are listed.');
        }

        $anchors = [];

        foreach ($this->list($data, 'anchors', $path) as $i => $anchor) {
            $anchors[] = $this->attempt("{$path}.anchors.{$i}", fn (): CalendarAnchor => is_array($anchor) ? new CalendarAnchor(
                $this->string($anchor, 'id'),
                CivilDate::parse($this->string($anchor, 'date')),
                $this->string($anchor, 'position'),
                RuleRegistry::range($anchor['effective'] ?? null),
                $this->strings($anchor['sources'] ?? []),
            ) : throw new InvalidArgumentException('must be an object.'));
        }

        $exceptions = [];

        foreach ($this->list($data, 'exceptions', $path, required: false) as $i => $exception) {
            $exceptions[] = $this->attempt("{$path}.exceptions.{$i}", fn (): CalendarException => is_array($exception) ? new CalendarException(
                $this->string($exception, 'id'),
                CivilDate::parse($this->string($exception, 'date')),
                $this->string($exception, 'position'),
                $this->term($exception['reason'] ?? null),
                $this->strings($exception['sources'] ?? []),
            ) : throw new InvalidArgumentException('must be an object.'));
        }

        if (count($this->violations) > $before) {
            return null;
        }

        return $this->attempt($path, fn (): CalendarCycle => new CalendarCycle(
            id: $this->string($data, 'id'),
            name: $this->term($data['name'] ?? null) ?? Term::of($this->string($data, 'id')),
            positions: array_values(array_filter($positions)),
            anchors: array_values(array_filter($anchors)),
            exceptions: array_values(array_filter($exceptions)),
            effective: RuleRegistry::range($data['effective'] ?? null),
            continuity: CycleContinuity::tryFrom(is_string($data['continuity'] ?? null) ? $data['continuity'] : 'assumed') ?? throw new InvalidArgumentException('continuity must be assumed or verified.'),
        ));
    }

    /** @return array<string, scalar|null> */
    private function parameters(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('must be an object of scalar driver settings.');
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (! is_scalar($item) && $item !== null) {
                throw new InvalidArgumentException("[{$key}] must be a scalar.");
            }

            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @return array<string, list<CalendarName>> */
    private function names(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('must map a unit (e.g. "month") to a list of {id, label}.');
        }

        $out = [];

        foreach ($value as $unit => $list) {
            if (! is_array($list) || ! array_is_list($list)) {
                throw new InvalidArgumentException("[{$unit}] must be a list of {id, label}.");
            }

            $out[(string) $unit] = array_map(fn (mixed $item): CalendarName => is_array($item)
                ? new CalendarName($this->string($item, 'id'), $this->term($item['label'] ?? null) ?? throw new InvalidArgumentException("[{$unit}] entries need a label."))
                : throw new InvalidArgumentException("[{$unit}] entries must be objects."), $list);
        }

        return $out;
    }

    private function epoch(mixed $value): ?CalendarEpoch
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('must be {date, label?}.');
        }

        return new CalendarEpoch(CivilDate::parse($this->string($value, 'date')), $this->term($value['label'] ?? null));
    }

    private function metadata(mixed $value): CalendarMetadata
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('must be an object.');
        }

        $assumptions = [];

        foreach (is_array($value['assumptions'] ?? null) ? $value['assumptions'] : [] as $assumption) {
            if (! is_array($assumption)) {
                throw new InvalidArgumentException('assumptions must be objects.');
            }

            $assumptions[] = new Assumption(
                $this->string($assumption, 'id'),
                $this->string($assumption, 'statement'),
                VerificationStatus::tryFrom(is_string($assumption['status'] ?? null) ? $assumption['status'] : 'draft') ?? throw new InvalidArgumentException('unknown assumption status.'),
            );
        }

        $extra = $value['extra'] ?? [];

        return new CalendarMetadata(
            description: $this->term($value['description'] ?? null),
            region: is_string($value['region'] ?? null) ? $value['region'] : null,
            verification: VerificationStatus::tryFrom(is_string($value['verification'] ?? null) ? $value['verification'] : 'draft') ?? throw new InvalidArgumentException('unknown verification status.'),
            assumptions: $assumptions,
            sources: $this->strings($value['sources'] ?? []),
            tags: $this->strings($value['tags'] ?? []),
            extra: is_array($extra) ? $extra : throw new InvalidArgumentException('extra must be an object.'),
        );
    }

    private function term(mixed $value): ?Term
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_array($value)) {
            throw new InvalidArgumentException('must be a string or a term object.');
        }

        /** @var string|array<string, mixed> $value */
        return Term::fromValue($value);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T|null
     */
    private function attempt(string $path, callable $build): mixed
    {
        try {
            return $build();
        } catch (InvalidDefinition $e) {
            array_push($this->violations, ...$e->violations);
        } catch (InvalidArgumentException $e) {
            $this->violations[] = new Violation($path, $e->getMessage());
        } catch (Throwable $e) {
            $this->violations[] = new Violation($path, $e->getMessage());
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<mixed>
     */
    private function list(array $data, string $key, string $path, bool $required = true): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            $this->violations[] = new Violation("{$path}.{$key}", 'must be a list.');

            return [];
        }

        if ($required && $value === []) {
            $this->violations[] = new Violation("{$path}.{$key}", 'needs at least one entry.');
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException("[{$key}] is required.");
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value) || array_filter($value, 'is_string') !== $value) {
            throw new InvalidArgumentException('must be a list of strings.');
        }

        return array_values($value);
    }
}
