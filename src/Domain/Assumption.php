<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Governance\VerificationStatus;

/**
 * A stated assumption a definition depends on, with how far it is verified.
 * Assumptions are surfaced by diagnostics and validation warnings so they
 * are never mistaken for established fact.
 */
final readonly class Assumption
{
    public function __construct(
        public string $id,
        public string $statement,
        public VerificationStatus $status = VerificationStatus::Draft,
    ) {
        Identifier::assert($id, 'assumption identifier');
    }

    /** @return array{id: string, statement: string, status: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'statement' => $this->statement, 'status' => $this->status->value];
    }
}
