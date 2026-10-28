<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Tenancy\TenantResolver;

it('keeps tenant calendars invisible to other tenants and to the global scope', function () {
    NwehMundani::register(definition(cyclicCalendar('private-cal'), 'tenant-a'));

    expect(NwehMundani::forTenant('tenant-a')->has('private-cal'))->toBeTrue()
        ->and(NwehMundani::forTenant('tenant-b')->has('private-cal'))->toBeFalse()
        ->and(NwehMundani::has('private-cal'))->toBeFalse()
        ->and(fn () => NwehMundani::forTenant('tenant-b')->calendar('private-cal'))->toThrow(CalendarNotFound::class)
        ->and(array_keys(NwehMundani::forTenant('tenant-b')->calendars()))->not->toContain('private-cal');
});

it('lets a tenant shadow a global calendar for itself only when the policy allows it', function () {
    config()->set('nweh-mundani.tenancy.allow_shadowing', true);
    NwehMundani::register(definition(cyclicCalendar('nweh', anchorDate: '2026-10-02', overrides: ['name' => 'Tenant Nweh']), 'tenant-a'));

    expect(NwehMundani::forTenant('tenant-a')->calendar('nweh')->name())->toBe('Tenant Nweh')
        ->and(NwehMundani::forTenant('tenant-b')->calendar('nweh')->name())->toBe('Nweh')
        ->and(NwehMundani::calendar('nweh')->fromCivil('2026-10-01')->label())->toBe('Ankoah');
});

it('reads the current tenant from the resolver on every call', function () {
    $resolver = new class implements TenantResolver
    {
        public ?string $tenant = null;

        public function current(): ?string
        {
            return $this->tenant;
        }
    };

    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(CalendarManager::class);
    NwehMundani::clearResolvedInstances();
    NwehMundani::register(definition(cyclicCalendar('scoped'), 'acme'));

    expect(NwehMundani::has('scoped'))->toBeFalse();
    $resolver->tenant = 'acme';
    expect(NwehMundani::has('scoped'))->toBeTrue()
        ->and(NwehMundani::context()->tenant)->toBe('acme');
});

it('rejects malformed tenant keys', function () {
    expect(fn () => NwehMundani::forTenant('../etc'))->toThrow(InvalidArgumentException::class);
});

it('lets the global calendar win by default, ignoring a tenant duplicate', function () {
    NwehMundani::register(definition(cyclicCalendar('nweh', anchorDate: '2026-10-02', overrides: ['name' => 'Tenant Nweh']), 'tenant-a'));

    expect(NwehMundani::forTenant('tenant-a')->calendar('nweh')->name())->toBe('Nweh');
});
