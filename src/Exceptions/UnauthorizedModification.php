<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class UnauthorizedModification extends NwehMundaniException
{
    public static function forAbility(string $ability, string $subject): self
    {
        return new self("Not authorised to {$ability} [{$subject}].");
    }
}
