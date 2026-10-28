<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

/**
 * Lifecycle of a calendar version:
 *
 *   draft → under-review → approved → published → superseded
 *             ↘ draft (rejected)  ↘ draft (approval withdrawn)
 *
 * Only drafts may change content. A version under review or approved is
 * frozen while it waits; sending it back to draft unfreezes it. A published
 * version is frozen for good and can only be superseded by a newer one.
 */
enum VersionStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under-review';
    case Approved = 'approved';
    case Published = 'published';
    case Superseded = 'superseded';

    public function isMutable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether the version has ever been live (its content is history). */
    public function isReleased(): bool
    {
        return $this === self::Published || $this === self::Superseded;
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::UnderReview, self::Published],
            self::UnderReview => [self::Approved, self::Draft],
            self::Approved => [self::Published, self::Draft],
            self::Published => [self::Superseded],
            self::Superseded => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
