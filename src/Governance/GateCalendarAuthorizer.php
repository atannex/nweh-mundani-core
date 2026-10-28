<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gate-backed authorisation. Define the abilities in your application:
 *
 *   Gate::define('nweh-mundani.publish-calendars', fn (User $user, string $tenant, string $calendar) => …);
 *
 * Undefined abilities and anonymous actors are denied.
 */
final readonly class GateCalendarAuthorizer implements CalendarAuthorizer
{
    public function __construct(private Gate $gate) {}

    public function authorize(string $ability, Authenticatable|SystemActor|null $actor, string $tenant, string $subject): void
    {
        if (! $this->allows($ability, $actor, $tenant, $subject)) {
            throw UnauthorizedModification::forAbility($ability, $subject);
        }
    }

    public function allows(string $ability, Authenticatable|SystemActor|null $actor, string $tenant, string $subject): bool
    {
        if ($actor instanceof SystemActor) {
            return true;
        }

        return $actor !== null && $this->gate->has($ability) && $this->gate->forUser($actor)->allows($ability, [$tenant, $subject]);
    }
}
