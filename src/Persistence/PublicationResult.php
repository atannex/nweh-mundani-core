<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;

final readonly class PublicationResult
{
    public function __construct(
        public CalendarDefinition $definition,
        public ?string $superseded = null,
    ) {}
}
