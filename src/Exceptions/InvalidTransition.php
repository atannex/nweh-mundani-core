<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

/** A lifecycle move that the state machine does not allow. */
final class InvalidTransition extends NwehMundaniException
{
    /** @param list<string> $allowed */
    public static function between(string $subject, string $from, string $to, array $allowed): self
    {
        return new self("{$subject} cannot move from [{$from}] to [{$to}]; allowed: ".($allowed === [] ? 'none' : implode(', ', $allowed)).'.');
    }

    public static function because(string $subject, string $reason): self
    {
        return new self("{$subject}: {$reason}");
    }
}
