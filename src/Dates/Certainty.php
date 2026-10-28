<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

/**
 * How a calculated value is supported.
 */
enum Certainty: string
{
    /** The date is itself an anchor or explicit override: the value is recorded, not computed. */
    case Recorded = 'recorded';

    /** Computed from an anchor through a cycle whose continuity has been verified. */
    case Derived = 'derived';

    /** Computed from an anchor through a cycle whose unbroken repetition is only assumed. */
    case Assumed = 'assumed';
}
