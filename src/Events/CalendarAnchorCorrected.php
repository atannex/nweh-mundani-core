<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An anchor was corrected in a draft (it takes effect when that version is published).
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class CalendarAnchorCorrected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $calendar,
        public string $version,
        public string $cycle,
        public string $anchor,
        public string $previousDate,
        public string $previousPosition,
        public string $date,
        public string $position,
        public ?string $actor = null,
    ) {}
}
