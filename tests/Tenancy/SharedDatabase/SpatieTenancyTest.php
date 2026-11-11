<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarDiagnostics;
use Atannex\NwehMundani\Core\Persistence\StorageConnection;
use Atannex\NwehMundani\Core\Tenancy\SpatieMultitenancy;
use Atannex\NwehMundani\Core\Tenancy\SpatieTenantResolver;
use Atannex\NwehMundani\Core\Tenancy\TenantResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Models\Tenant;
use Spatie\Multitenancy\Tasks\PrefixCacheTask;

afterEach(fn () => Tenant::forgetCurrent());

it('follows the current Spatie tenant', function () {
    $resolver = app(TenantResolver::class);
    $acme = tenant('Acme');

    expect($resolver)->toBeInstanceOf(SpatieTenantResolver::class)
        ->and($resolver->current())->toBeNull();

    $acme->makeCurrent();
    expect($resolver->current())->toBe($acme->getKey())
        ->and(NwehMundani::context()->tenant)->toBe((string) $acme->getKey());

    Tenant::forgetCurrent();
    expect(NwehMundani::context()->tenant)->toBe('');
});

it('keeps each tenant to its own calendars and events, and shares the global ones', function () {
    $acme = tenant('Acme');
    $rival = tenant('Rival');

    $acme->makeCurrent();
    publishHere(cyclicCalendar('parish'));
    app(ObservanceManagementService::class)->create(['id' => 'meeting', 'calendar' => 'nweh', 'name' => 'Meeting', 'category' => 'meeting',
        'rules' => [['type' => 'fixed-date', 'parameters' => ['date' => '2026-10-28']]]], new SystemActor('tests'), publish: true);

    expect(NwehMundani::calendar('parish')->name())->toBe('Parish')
        ->and(NwehMundani::calendar('nweh')->observancesOn('2026-10-28'))->toHaveCount(1);

    $rival->makeCurrent();
    expect(fn () => NwehMundani::calendar('parish'))->toThrow(CalendarNotFound::class)
        ->and(NwehMundani::calendar('nweh')->observancesOn('2026-10-28'))->toBe([])
        ->and(NwehMundani::calendar('nweh')->fromCivil('2026-10-28')->label())->toBe('Amina');

    expect(DB::table('nmc_calendars')->where('identifier', 'parish')->value('tenant_key'))->toBe((string) $acme->getKey());
});

it("keeps one cache prefix when Spatie's PrefixCacheTask changes it per tenant", function () {
    config()->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null]);
    config()->set('cache.default', 'database');
    config()->set('multitenancy.switch_tenant_tasks', [PrefixCacheTask::class]);
    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    $base = config('cache.prefix');

    // The package is first used inside a tenant, as in a queued job.
    tenant('Acme')->makeCurrent();
    expect(config('cache.prefix'))->not->toBe($base);

    $global = new CalendarContext;
    $actor = new SystemActor('tests');
    $workflow = app(CalendarWorkflowService::class);
    $workflow->saveDraft(cyclicCalendar('shared'), $actor, $global);
    $workflow->submitForReview('shared', '1.0.0', $actor, context: $global);
    $workflow->approve('shared', '1.0.0', $actor, context: $global);
    $workflow->publish('shared', '1.0.0', $actor, $global);
    expect(NwehMundani::withContext($global)->calendar('shared')->name())->toBe('Shared');

    $keys = DB::table('cache')->pluck('key');
    expect($keys)->not->toBeEmpty()
        ->and($keys->every(fn (string $key) => str_starts_with($key, $base.'nmc:')))->toBeTrue();
});

it('reports Spatie in the diagnostics', function () {
    $check = collect(app(CalendarDiagnostics::class)->run())->firstWhere('name', 'tenancy');

    expect($check->status->value)->toBe('ok')
        ->and($check->message)->toContain('spatie/laravel-multitenancy')
        ->and($check->message)->toContain('shared database')
        ->and(StorageConnection::name())->toBeNull();
});

it('treats one shared database as shared, so its migrations load as usual', function () {
    expect(SpatieMultitenancy::databasePerTenant())->toBeFalse();
});
