<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

enum OverrideAction: string
{
    /** The occurrence on this date does not take place. */
    case Cancel = 'cancel';

    /** The occurrence on this date moves to another date. */
    case Reschedule = 'reschedule';
}
