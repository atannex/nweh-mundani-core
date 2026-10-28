<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

/**
 * Whether a stored calendar as a whole is in service, independent of its
 * versions. Suspended and archived calendars do not resolve (and do not fall
 * back to a code-registered calendar of the same id); their history stays
 * readable through the query service.
 */
enum CalendarStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [self::Active],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
