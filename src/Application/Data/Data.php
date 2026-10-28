<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Base for the read models the query service returns. They are plain,
 * immutable values: no HTML, no CSS classes, no component props. Labels are
 * already localised for the requested locale, and the exact source spelling
 * travels next to every label as `original`. toArray() and JSON output
 * are stable contracts (snake_case keys, ISO dates).
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class Data implements Arrayable, JsonSerializable
{
    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  iterable<Data>  $items
     * @return list<array<string, mixed>>
     */
    protected static function all(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = $item->toArray();
        }

        return $out;
    }
}
