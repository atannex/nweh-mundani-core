<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Kernel\CalendarDiagnostics;
use Atannex\NwehMundani\Core\Persistence\StorageConnection;
use Atannex\NwehMundani\Core\Tenancy\SpatieMultitenancy;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Models\Tenant;

afterEach(fn () => Tenant::forgetCurrent());

function tenancyCheck(): object
{
    return collect(app(CalendarDiagnostics::class)->run())->firstWhere('name', 'tenancy');
}

it('keeps the tables on the landlord connection, apart from every tenant database', function () {
    expect(StorageConnection::name())->toBe('testing');

    publishHere(cyclicCalendar('shared'));

    tenant('Acme', ':memory:')->makeCurrent();
    publishHere(cyclicCalendar('parish'));

    expect(NwehMundani::calendar('shared')->name())->toBe('Shared')
        ->and(NwehMundani::calendar('parish')->name())->toBe('Parish')
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('nmc_calendars'))->toBeFalse()
        ->and(DB::connection('testing')->table('nmc_calendars')->where('identifier', 'parish')->value('tenant_key'))->toBe((string) Tenant::current()->getKey());

    tenant('Rival', ':memory:')->makeCurrent();
    expect(fn () => NwehMundani::calendar('parish'))->toThrow(CalendarNotFound::class)
        ->and(NwehMundani::calendar('shared')->name())->toBe('Shared');
});

it('treats the setup as a database per tenant, so its migrations stay out of tenants:artisan migrate', function () {
    expect(SpatieMultitenancy::databasePerTenant())->toBeTrue();
});

it('reports the landlord storage, and refuses tables on the tenant connection', function () {
    expect(tenancyCheck()->status->value)->toBe('ok')
        ->and(tenancyCheck()->message)->toContain('a database per tenant, nmc_* tables on [testing]');

    config()->set('nweh-mundani.persistence.connection', 'tenant');

    expect(tenancyCheck()->status->value)->toBe('failure')
        ->and(tenancyCheck()->message)->toContain('NWEH_MUNDANI_DB_CONNECTION');
});
