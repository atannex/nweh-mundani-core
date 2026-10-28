<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use DateTimeImmutable;

/**
 * One recorded change. $changes is a bounded summary (identifiers, hashes,
 * statuses), not a copy of the content.
 */
final readonly class RevisionEntry
{
    /** @param array<string, scalar|null> $changes */
    public function __construct(
        public string $tenant,
        public string $subjectType,
        public string $subjectKey,
        public string $action,
        public ?string $actor,
        public array $changes = [],
        public DateTimeImmutable $at = new DateTimeImmutable,
    ) {}
}
