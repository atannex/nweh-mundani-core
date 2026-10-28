<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\Verification;

/**
 * Append-only history: every change (revisions) and every verification
 * decision. Entries are never updated or deleted.
 */
interface AuditTrail
{
    public function recordRevision(RevisionEntry $entry): void;

    public function recordVerification(Verification $verification): void;

    /** @return list<RevisionEntry> Oldest first. */
    public function revisions(string $tenant, string $subjectType, string $subjectKey): array;

    /** @return list<Verification> Oldest first. */
    public function verifications(string $tenant, string $subjectType, string $subjectKey): array;
}
