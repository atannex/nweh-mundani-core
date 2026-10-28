<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Tenancy\TenantKey;

/**
 * Who a calendar is: a stable identifier within an owning tenant.
 * The empty tenant key ('') means the calendar is global (shared).
 */
final readonly class CalendarIdentity
{
    public string $tenant;

    public function __construct(
        public string $id,
        ?string $tenant = null,
    ) {
        Identifier::assert($id, 'calendar identifier');
        $this->tenant = TenantKey::normalise($tenant);
    }

    public function isGlobal(): bool
    {
        return $this->tenant === TenantKey::GLOBAL;
    }

    public function key(): string
    {
        return $this->tenant.'|'.$this->id;
    }
}
