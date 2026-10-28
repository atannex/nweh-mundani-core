<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

enum OccurrenceState: string
{
    case Scheduled = 'scheduled';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
}
