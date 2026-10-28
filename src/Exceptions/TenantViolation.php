<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class TenantViolation extends NwehMundaniException
{
    public static function crossing(string $expected, string $actual, string $subject): self
    {
        $show = static fn (string $key): string => $key === '' ? '(global)' : $key;

        return new self("{$subject} belongs to tenant [{$show($actual)}], not [{$show($expected)}].");
    }
}
