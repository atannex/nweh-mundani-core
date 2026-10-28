<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A stored calendar was suspended, archived or reactivated.
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class CalendarStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $calendar,
        public string $from,
        public string $to,
        public ?string $actor = null,
    ) {}
}
