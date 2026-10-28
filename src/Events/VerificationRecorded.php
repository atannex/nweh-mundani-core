<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A verification decision was recorded.
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class VerificationRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $subjectType,
        public string $subjectKey,
        public string $status,
        public ?string $previous,
        public ?string $actor = null,
    ) {}
}
