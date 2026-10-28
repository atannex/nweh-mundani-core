<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

/**
 * Who may see an observance. Enforced server-side by ObservanceGate, never
 * left to the UI.
 */
enum Visibility: string
{
    /** Anyone. */
    case Public = 'public';

    /** Only viewers granted the "nweh-mundani.view-restricted" ability. */
    case Restricted = 'restricted';

    /** Only viewers in the owning tenant who are granted "nweh-mundani.view-private". */
    case Private = 'private';
}
