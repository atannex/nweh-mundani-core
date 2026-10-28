<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Contracts;

use Atannex\NwehMundani\Core\Drivers\DriverKind;

/**
 * A calculation algorithm. Implement the capability interfaces
 * (CivilDateConvertible, CycleAware…) for the operations it really supports;
 * a driver claims nothing by implementing this interface alone.
 */
interface CalendarDriver
{
    /** Key definitions use to select this driver ("cyclic"). */
    public function key(): string;

    public function kind(): DriverKind;

    /** Algorithm version; part of cache keys so a fix invalidates old results. */
    public function version(): string;
}
