<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers;

/**
 * The family a driver's algorithm belongs to. Purely descriptive: what a
 * driver can do is decided by the capability interfaces it implements.
 */
enum DriverKind: string
{
    case Cyclic = 'cyclic';
    case Solar = 'solar';
    case Lunar = 'lunar';
    case Lunisolar = 'lunisolar';
    case RuleBased = 'rule-based';
    case Custom = 'custom';
}
