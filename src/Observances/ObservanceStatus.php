<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

/**
 * Publication state of an observance. Only published observances produce
 * occurrences for readers; drafts are visible to management services only.
 */
enum ObservanceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /** Withdrawn as a whole (individual dates are cancelled with an override instead). */
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Cancelled],
            self::Published => [self::Cancelled, self::Draft],
            self::Cancelled => [self::Draft],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
