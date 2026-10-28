<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use DateTimeZone;

/**
 * The explicit circumstances of a calendar operation: which tenant's data
 * it may see, which locale labels render in, and the timezone used to turn
 * instants (DateTime objects) into civil dates. Immutable; with*() copies.
 */
final readonly class CalendarContext
{
    public string $tenant;

    public function __construct(
        string|int|null $tenant = null,
        public ?string $locale = null,
        public string $timezone = 'UTC',
    ) {
        $this->tenant = TenantKey::normalise($tenant);
        new DateTimeZone($timezone);
    }

    public function withTenant(string|int|null $tenant): self
    {
        return new self($tenant, $this->locale, $this->timezone);
    }

    public function withLocale(?string $locale): self
    {
        return new self($this->tenant, $locale, $this->timezone);
    }

    public function withTimezone(string $timezone): self
    {
        return new self($this->tenant, $this->locale, $timezone);
    }

    public function isGlobal(): bool
    {
        return $this->tenant === TenantKey::GLOBAL;
    }
}
