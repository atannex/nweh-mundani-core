<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use DateTimeImmutable;

/**
 * A verification decision about a subject (a calendar version, an anchor,
 * an observance, a source, an assumption). The latest decision is current.
 */
final readonly class Verification
{
    public function __construct(
        public string $tenant,
        public string $subjectType,
        public string $subjectKey,
        public VerificationStatus $status,
        public ?string $actor,
        public ?string $notes = null,
        public DateTimeImmutable $at = new DateTimeImmutable,
    ) {}
}
