<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An observance was created (as a draft).
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class ObservanceCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $calendar,
        public string $observance,
        public ?string $actor = null,
    ) {}
}
