<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarRegistry;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemoryObservanceRepository;

/**
 * Observances registered in code (config and definition providers). They
 * load together with code-registered calendars, on first use.
 */
final readonly class StaticObservanceResolver implements ObservanceResolver
{
    public function __construct(
        private CalendarRegistry $registry,
        private InMemoryObservanceRepository $observances,
    ) {}

    public function candidates(CalendarContext $context, string $calendar): iterable
    {
        $this->registry->boot();

        return (new RepositoryObservanceResolver($this->observances))->candidates($context, $calendar);
    }
}
