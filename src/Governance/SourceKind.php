<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

enum SourceKind: string
{
    /** Published or archived document. */
    case Documentary = 'documentary';

    /** Recorded or transcribed oral history. */
    case OralHistory = 'oral-history';

    /** A recognised community custodian of the knowledge. */
    case Custodian = 'custodian';

    /** Supplied by a contributor or maintainer, not yet traced to a primary source. */
    case Contributed = 'contributed';
}
