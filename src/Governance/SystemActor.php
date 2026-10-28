<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

/**
 * Trusted application code acting on its own behalf (seeders, migrations,
 * console commands). Only PHP code can construct one, so no request input
 * can claim it. The authorizer always allows it.
 */
final readonly class SystemActor
{
    public function __construct(public string $name = 'system') {}
}
