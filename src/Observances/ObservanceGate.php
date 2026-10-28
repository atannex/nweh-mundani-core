<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Server-side visibility check for observances. Restricted and private
 * observances are denied unless the application defines the abilities and
 * grants them; nothing is shown by default that the application did not allow.
 */
final readonly class ObservanceGate
{
    public const string VIEW_RESTRICTED = 'nweh-mundani.view-restricted';

    public const string VIEW_PRIVATE = 'nweh-mundani.view-private';

    public function __construct(private ?Gate $gate = null) {}

    public function allows(Observance $observance, CalendarContext $context, ?Authenticatable $viewer): bool
    {
        if ($observance->tenant !== $context->tenant && ! $observance->isGlobal()) {
            return false;
        }

        return match ($observance->visibility) {
            Visibility::Public => true,
            Visibility::Restricted => $this->check(self::VIEW_RESTRICTED, $observance, $viewer),
            Visibility::Private => $observance->tenant === $context->tenant && $this->check(self::VIEW_PRIVATE, $observance, $viewer),
        };
    }

    /**
     * Sources carry custodian names and notes that a community may not want
     * public; the same visibility levels and abilities apply as for observances.
     */
    public function allowsSource(Source $source, CalendarContext $context, ?Authenticatable $viewer): bool
    {
        if ($source->tenant !== $context->tenant && $source->tenant !== TenantKey::GLOBAL) {
            return false;
        }

        return match ($source->visibility) {
            Visibility::Public => true,
            Visibility::Restricted => $this->allowed(self::VIEW_RESTRICTED, $source, $viewer),
            Visibility::Private => $source->tenant === $context->tenant && $this->allowed(self::VIEW_PRIVATE, $source, $viewer),
        };
    }

    private function allowed(string $ability, object $subject, ?Authenticatable $viewer): bool
    {
        if ($this->gate === null || $viewer === null || ! $this->gate->has($ability)) {
            return false;
        }

        return $this->gate->forUser($viewer)->allows($ability, [$subject]);
    }

    private function check(string $ability, Observance $observance, ?Authenticatable $viewer): bool
    {
        if ($this->gate === null || $viewer === null || ! $this->gate->has($ability)) {
            return false;
        }

        return $this->gate->forUser($viewer)->allows($ability, [$observance]);
    }
}
