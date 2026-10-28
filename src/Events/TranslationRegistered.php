<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A translation was registered or removed for a calendar term.
 *
 * Payloads are scalars only (tenant key and identifiers), so the event is
 * safe to queue and never carries another tenant's data or a model.
 * Dispatched only once any surrounding database transaction commits.
 */
final readonly class TranslationRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenant,
        public string $calendar,
        public string $termKey,
        public string $locale,
        public string $action,
        public ?string $actor = null,
    ) {}
}
