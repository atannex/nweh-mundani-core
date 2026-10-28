<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A definition's version: a semantic version number plus lifecycle status.
 */
final readonly class CalendarVersion
{
    public function __construct(
        public string $number,
        public VersionStatus $status = VersionStatus::Published,
        public ?DateTimeImmutable $publishedAt = null,
    ) {
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $number)) {
            throw new InvalidArgumentException("[{$number}] is not a semantic version (MAJOR.MINOR.PATCH).");
        }
    }

    public function compareTo(self $other): int
    {
        return version_compare($this->number, $other->number);
    }

    public function withStatus(VersionStatus $status, ?DateTimeImmutable $publishedAt = null): self
    {
        return new self($this->number, $status, $publishedAt ?? $this->publishedAt);
    }
}
