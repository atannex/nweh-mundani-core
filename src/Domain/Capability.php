<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

enum Capability: string
{
    /** Civil date → calendar representation. */
    case FromCivil = 'from-civil';

    /** Calendar representation → civil date. */
    case ToCivil = 'to-civil';

    /** Enumerate the periods (cycle runs, months…) a date falls in. */
    case Periods = 'periods';

    /** Find occurrences of cycle positions. */
    case CycleQueries = 'cycle-queries';

    /** Leap / intercalation rules. */
    case LeapRules = 'leap-rules';

    /** Add or subtract calendar units. */
    case Arithmetic = 'arithmetic';

    /** Driver-specific validation beyond the structural checks. */
    case DriverValidation = 'driver-validation';
}
