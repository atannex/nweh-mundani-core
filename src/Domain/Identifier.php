<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use InvalidArgumentException;

/**
 * Stable technical identifiers (calendars, cycles, positions, anchors, rules).
 *
 * Identifiers are lowercase ASCII slugs so they make safe database keys,
 * cache keys and URLs. They are never shown as cultural terms: the original
 * spelling lives in a Term next to them.
 */
final class Identifier
{
    public const string PATTERN = '/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/';

    public const int MAX_LENGTH = 100;

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::PATTERN, $value) === 1;
    }

    public static function assert(string $value, string $what = 'identifier'): string
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException("[{$value}] is not a valid {$what}: use lowercase letters, digits and single - _ . separators (max ".self::MAX_LENGTH.' characters).');
        }

        return $value;
    }
}
