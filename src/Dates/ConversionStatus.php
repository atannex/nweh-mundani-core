<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

enum ConversionStatus: string
{
    /** Every cycle/component of the calendar resolved. */
    case Resolved = 'resolved';

    /** Some cycles resolved; others are not in force on the date. */
    case Partial = 'partial';

    /** The driver found more than one valid representation. */
    case Ambiguous = 'ambiguous';

    /** The date is outside the calendar's effective range or every cycle's. */
    case OutOfRange = 'out-of-range';

    /** The calendar's driver cannot perform this conversion. */
    case Unsupported = 'unsupported';
}
