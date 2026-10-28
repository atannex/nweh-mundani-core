<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Illuminate\Contracts\Auth\Authenticatable;

/** How actors are written into audit records: "user:<id>", the system actor's name, or null. */
final class Actor
{
    public static function name(Authenticatable|SystemActor|null $actor): ?string
    {
        return match (true) {
            $actor instanceof SystemActor => $actor->name,
            $actor instanceof Authenticatable => 'user:'.(is_scalar($id = $actor->getAuthIdentifier()) ? (string) $id : '?'),
            default => null,
        };
    }
}
