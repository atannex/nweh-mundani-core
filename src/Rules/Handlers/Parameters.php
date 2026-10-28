<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules\Handlers;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Kernel\Violation;
use InvalidArgumentException;

/**
 * Shared strict readers for rule parameters. Every reader records a
 * violation instead of throwing so a handler can report all problems at once.
 */
final class Parameters
{
    /** @var list<Violation> */
    public array $violations = [];

    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values, private readonly string $prefix = 'parameters') {}

    /** @param list<string> $allowed */
    public function onlyKeys(array $allowed): void
    {
        foreach (array_keys($this->values) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail((string) $key, 'is not a recognised parameter.');
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function date(string $key, bool $required = true): ?CivilDate
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $required ? $this->fail($key, 'is required (YYYY-MM-DD).') : null;
        }

        try {
            return is_string($value) ? CivilDate::parse($value) : $this->fail($key, 'must be a YYYY-MM-DD string.');
        } catch (InvalidArgumentException $e) {
            return $this->fail($key, $e->getMessage());
        }
    }

    public function int(string $key, ?int $min = null, ?int $max = null, bool $required = true, ?int $default = null): ?int
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $required ? $this->fail($key, 'is required.') : $default;
        }

        if (! is_int($value)) {
            return $this->fail($key, 'must be an integer.');
        }

        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            return $this->fail($key, 'must be between '.($min ?? '-∞').' and '.($max ?? '∞').'.');
        }

        return $value;
    }

    public function identifier(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        if (! is_string($value) || ! Identifier::isValid($value)) {
            return $this->fail($key, 'must be an identifier.');
        }

        return $value;
    }

    /** @param list<string> $choices */
    public function choice(string $key, array $choices): ?string
    {
        $value = $this->values[$key] ?? null;

        if (! is_string($value) || ! in_array($value, $choices, true)) {
            return $this->fail($key, 'must be one of: '.implode(', ', $choices).'.');
        }

        return $value;
    }

    /** @return list<mixed>|null */
    public function list(string $key, int $max = 1000): ?array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return $this->fail($key, 'must be a non-empty list.');
        }

        if (count($value) > $max) {
            return $this->fail($key, "may hold at most {$max} entries.");
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    public function object(string $key): ?array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value) || array_is_list($value)) {
            return $this->fail($key, 'must be an object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function ok(): bool
    {
        return $this->violations === [];
    }

    public function fail(string $key, string $message): null
    {
        $this->violations[] = new Violation("{$this->prefix}.{$key}", $message);

        return null;
    }

    /** @return list<Violation> */
    public static function checkPosition(CalendarDefinition $definition, string $cycle, string $position, string $path): array
    {
        $subject = $definition->cycle($cycle);

        if ($subject === null) {
            return [new Violation($path, "calendar [{$definition->id()}] has no cycle [{$cycle}].")];
        }

        return $subject->position($position) === null
            ? [new Violation($path, "cycle [{$cycle}] has no position [{$position}].")]
            : [];
    }
}
