<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

use RuntimeException;

/**
 * Base class for every exception the package throws, so applications can
 * catch the whole family with one clause.
 *
 * errorKey() is a stable, machine-readable identifier (kebab-case class
 * name, e.g. "calendar-not-found") suitable for API error payloads; the
 * message is for humans and may change between releases.
 */
class NwehMundaniException extends RuntimeException
{
    public function errorKey(): string
    {
        $short = substr(strrchr(static::class, '\\') ?: static::class, 1) ?: static::class;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
    }

    /** @return array{error: string, message: string} */
    public function toArray(): array
    {
        return ['error' => $this->errorKey(), 'message' => $this->getMessage()];
    }
}
