<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * One occurrence of an observance was cancelled, rescheduled or restored.
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class ObservanceOccurrenceChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $calendar,
        public string $observance,
        public string $date,
        public string $action,
        public ?string $rescheduledTo,
        public ?string $actor = null,
    ) {}
}
