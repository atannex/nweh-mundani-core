<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\Verification;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;

final class InMemoryAuditTrail implements AuditTrail
{
    /** @var list<RevisionEntry> */
    private array $revisions = [];

    /** @var list<Verification> */
    private array $verifications = [];

    public function recordRevision(RevisionEntry $entry): void
    {
        $this->revisions[] = $entry;
    }

    public function recordVerification(Verification $verification): void
    {
        $this->verifications[] = $verification;
    }

    public function revisions(string $tenant, string $subjectType, string $subjectKey): array
    {
        return array_values(array_filter($this->revisions, static fn (RevisionEntry $e): bool => $e->tenant === $tenant && $e->subjectType === $subjectType && $e->subjectKey === $subjectKey));
    }

    public function verifications(string $tenant, string $subjectType, string $subjectKey): array
    {
        return array_values(array_filter($this->verifications, static fn (Verification $v): bool => $v->tenant === $tenant && $v->subjectType === $subjectType && $v->subjectKey === $subjectKey));
    }
}
