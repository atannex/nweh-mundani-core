<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

final class ConflictingAnchors extends NwehMundaniException
{
    /** @param list<string> $anchors */
    public static function at(string $cycle, string $date, array $anchors): self
    {
        return new self(sprintf(
            'Cycle [%s] has anchors [%s] in force on %s that disagree about the position. Narrow their effective ranges; the engine will not choose between them.',
            $cycle,
            implode(', ', $anchors),
            $date,
        ));
    }
}
