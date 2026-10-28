<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

/**
 * How far a cycle's unbroken repetition between and beyond its anchors has
 * been established. This is about history, not arithmetic: an assumed cycle
 * still calculates exactly, but results away from anchor dates are labelled
 * as resting on that assumption.
 */
enum CycleContinuity: string
{
    /** Repetition with no skipped or inserted days is assumed, not yet checked against further dated records. */
    case Assumed = 'assumed';

    /** Repetition has been checked against independent dated records. */
    case Verified = 'verified';
}
