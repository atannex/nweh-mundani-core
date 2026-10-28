<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class UnknownRuleType extends NwehMundaniException
{
    public static function forType(string $type): self
    {
        return new self("Rule type [{$type}] has no registered handler. Unknown rule types are rejected rather than ignored.");
    }
}
