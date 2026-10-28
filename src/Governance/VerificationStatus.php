<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

/**
 * How far a historical or cultural claim has been verified. Independent of
 * mathematical validity: a cycle can compute flawlessly and still rest on
 * an unverified origin.
 */
enum VerificationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under-review';
    case Verified = 'verified';
    case Disputed = 'disputed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview, self::Rejected, self::Draft],
            self::UnderReview => [self::Verified, self::Disputed, self::Rejected],
            self::Verified => [self::Disputed, self::Superseded],
            self::Disputed => [self::UnderReview, self::Rejected, self::Superseded],
            self::Rejected => [self::Draft],
            self::Superseded => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
