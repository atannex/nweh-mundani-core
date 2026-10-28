<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Events\CalendarDraftSaved;
use Atannex\NwehMundani\Core\Events\CalendarVersionPublished;
use Atannex\NwehMundani\Core\Events\ObservancesChanged;
use Atannex\NwehMundani\Core\Events\VerificationRecorded;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Exceptions\TenantViolation;
use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Atannex\NwehMundani\Core\Exceptions\UnknownRuleType;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\CalendarAdministration;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\RevisionLogRecord;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

function admin(): CalendarAdministration
{
    return app(CalendarAdministration::class);
}

function systemActor(): SystemActor
{
    return new SystemActor('tests');
}

function ctx(?string $tenant = null): CalendarContext
{
    return new CalendarContext($tenant);
}

function publishCalendar(array $data, ?string $tenant = null): void
{
    $workflow = app(CalendarWorkflowService::class);
    $version = $data['version'] ?? '1.0.0';

    admin()->saveDraft($data, systemActor(), ctx($tenant));
    $workflow->submitForReview($data['id'], $version, systemActor(), context: ctx($tenant));
    $workflow->approve($data['id'], $version, systemActor(), context: ctx($tenant));
    admin()->publish($data['id'], $version, systemActor(), ctx($tenant));
}

it('denies anonymous actors and actors without a defined ability', function () {
    $user = (new User)->forceFill(['id' => 7]);

    expect(fn () => admin()->saveDraft(cyclicCalendar('x'), null, ctx()))->toThrow(UnauthorizedModification::class)
        ->and(fn () => admin()->saveDraft(cyclicCalendar('x'), $user, ctx()))->toThrow(UnauthorizedModification::class)
        ->and(DB::table('nmc_calendars')->count())->toBe(0);
});

it('delegates to Gate abilities with the tenant and subject', function () {
    $editor = (new User)->forceFill(['id' => 1]);
    $seen = [];
    Gate::define(CalendarAuthorizer::MANAGE_CALENDARS, function (User $user, string $tenant, string $calendar) use (&$seen) {
        $seen[] = [$tenant, $calendar];

        return $user->id === 1 && $tenant === 'acme';
    });

    admin()->saveDraft(cyclicCalendar('ok'), $editor, ctx('acme'));

    expect($seen)->toBe([['acme', 'ok']])
        ->and(fn () => admin()->saveDraft(cyclicCalendar('nope'), $editor, ctx('other')))->toThrow(UnauthorizedModification::class)
        // Saving a draft does not grant publishing.
        ->and(fn () => admin()->publish('ok', '1.0.0', $editor, ctx('acme')))->toThrow(UnauthorizedModification::class);
});

it('keeps writes inside the context tenant', function () {
    $foreign = definition(cyclicCalendar('foreign'), 'tenant-b');

    expect(fn () => admin()->saveDraft($foreign, systemActor(), ctx('tenant-a')))->toThrow(TenantViolation::class);
});

it('validates before publishing and leaves everything unchanged on failure', function () {
    admin()->saveDraft(cyclicCalendar('bad', cycle: ['anchors' => [
        ['id' => 'a', 'date' => '2026-10-01', 'position' => 'p1'],
        ['id' => 'b', 'date' => '2026-10-01', 'position' => 'p2'],
    ]]), systemActor(), ctx());

    expect(fn () => app(CalendarWorkflowService::class)->submitForReview('bad', '1.0.0', systemActor(), context: ctx()))->toThrow(InvalidDefinition::class)
        ->and(fn () => admin()->publish('bad', '1.0.0', systemActor(), ctx()))->toThrow(InvalidTransition::class)
        ->and(DB::table('nmc_calendar_versions')->value('status'))->toBe('draft')
        ->and(RevisionLogRecord::query()->where('action', 'published')->count())->toBe(0);
});

it('only lets versions move forward', function () {
    publishCalendar(cyclicCalendar('mono', overrides: ['version' => '2.0.0']));

    expect(fn () => admin()->saveDraft(cyclicCalendar('mono', overrides: ['version' => '1.5.0']), systemActor(), ctx()))->toThrow(InvalidDefinition::class, 'newer')
        ->and(fn () => admin()->saveDraft(cyclicCalendar('mono', overrides: ['version' => '2.0.0']), systemActor(), ctx()))->toThrow(ImmutableVersion::class);
});

it('serves stored calendars through the facade with the right precedence', function () {
    config()->set('nweh-mundani.tenancy.allow_shadowing', true);
    // A stored global "nweh" overrides the code-registered one.
    publishCalendar(cyclicCalendar('nweh', overrides: ['name' => 'Stored Nweh']));
    publishCalendar(cyclicCalendar('nweh', overrides: ['name' => 'Tenant Nweh']), 'acme');

    expect(NwehMundani::calendar('nweh')->name())->toBe('Stored Nweh')
        ->and(NwehMundani::forTenant('acme')->calendar('nweh')->name())->toBe('Tenant Nweh')
        ->and(NwehMundani::forTenant('other')->calendar('nweh')->name())->toBe('Stored Nweh')
        ->and(NwehMundani::calendar('mmock')->name())->toBe('Mmock');
});

it('never exposes drafts as the active calendar', function () {
    admin()->saveDraft(cyclicCalendar('drafty'), systemActor(), ctx());

    expect(fn () => NwehMundani::calendar('drafty'))->toThrow(CalendarNotFound::class)
        ->and(NwehMundani::calendar('drafty', '1.0.0')->version())->toBe('1.0.0');
});

it('isolates cache entries per tenant and invalidates them on publication', function () {
    config()->set('nweh-mundani.tenancy.allow_shadowing', true);
    publishCalendar(cyclicCalendar('cached', overrides: ['name' => 'Global v1']));
    publishCalendar(cyclicCalendar('cached', overrides: ['name' => 'Acme v1']), 'acme');

    expect(NwehMundani::calendar('cached')->name())->toBe('Global v1')
        ->and(NwehMundani::forTenant('acme')->calendar('cached')->name())->toBe('Acme v1');

    // Prove the second read is served from cache: change the row behind the repository's back.
    DB::table('nmc_calendar_versions')->where('name_original', 'Global v1')->update(['name_original' => 'Changed in DB']);
    expect(NwehMundani::calendar('cached')->name())->toBe('Global v1');

    publishCalendar(cyclicCalendar('cached', overrides: ['name' => 'Global v2', 'version' => '1.1.0']));

    expect(NwehMundani::calendar('cached')->name())->toBe('Global v2')
        ->and(NwehMundani::forTenant('acme')->calendar('cached')->name())->toBe('Acme v1');
});

it('builds cache keys from every dimension', function () {
    $cache = app(DefinitionCache::class);

    expect($cache->key('def', 'a', 'nweh', '1.0.0', 'g'))->not->toBe($cache->key('def', 'b', 'nweh', '1.0.0', 'g'))
        ->and($cache->key('def', '', 'nweh', '1.0.0', 'g'))->not->toBe($cache->key('def', '', 'nweh', '1.0.1', 'g'))
        ->and($cache->key('def', 'a|b', 'c', '1', 'g'))->not->toBe($cache->key('def', 'a', 'b|c', '1', 'g'));
});

it('stores observances, checks their rules against the calendar and keeps tenants apart', function () {
    publishCalendar(cyclicCalendar('town'), 'acme');
    $market = ['id' => 'market', 'calendar' => 'town', 'name' => 'Market', 'category' => 'market', 'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p2']]]]];

    admin()->saveObservance($market, systemActor(), ctx('acme'));

    expect(array_map(fn ($o) => $o->id, NwehMundani::forTenant('acme')->calendar('town')->observancesOn('2026-10-02')))->toBe(['market'])
        // Another tenant cannot attach to (or even see) acme's calendar.
        ->and(fn () => admin()->saveObservance($market, systemActor(), ctx('rival')))->toThrow(CalendarNotFound::class)
        ->and(fn () => admin()->saveObservance([...$market, 'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p99']]]]], systemActor(), ctx('acme')))->toThrow(InvalidDefinition::class)
        ->and(fn () => admin()->saveObservance([...$market, 'rules' => [['type' => 'eval', 'parameters' => []]]], systemActor(), ctx('acme')))->toThrow(UnknownRuleType::class);
});

it('lets tenants add observances to global calendars without leaking them', function () {
    admin()->saveObservance(['id' => 'meeting', 'calendar' => 'nweh', 'name' => 'Meeting', 'category' => 'meeting', 'rules' => [['type' => 'fixed-date', 'parameters' => ['date' => '2026-10-28']]]], systemActor(), ctx('acme'));

    expect(NwehMundani::forTenant('acme')->calendar('nweh')->observancesOn('2026-10-28'))->toHaveCount(1)
        ->and(NwehMundani::forTenant('rival')->calendar('nweh')->observancesOn('2026-10-28'))->toBe([])
        ->and(NwehMundani::calendar('nweh')->observancesOn('2026-10-28'))->toBe([]);

    admin()->deleteObservance('nweh', 'meeting', systemActor(), ctx('acme'));
    expect(NwehMundani::forTenant('acme')->calendar('nweh')->observancesOn('2026-10-28'))->toBe([]);
});

it('enforces verification transitions and keeps them apart from validity', function () {
    admin()->recordVerification('calendar', 'nweh@1.0.0', VerificationStatus::Submitted, systemActor(), ctx());
    admin()->recordVerification('calendar', 'nweh@1.0.0', VerificationStatus::UnderReview, systemActor(), ctx());

    expect(fn () => admin()->recordVerification('calendar', 'nweh@1.0.0', VerificationStatus::Superseded, systemActor(), ctx()))->toThrow(InvalidDefinition::class, 'cannot move')
        ->and(fn () => admin()->recordVerification('calendar', 'nweh@1.0.0', VerificationStatus::Verified, null, ctx()))->toThrow(UnauthorizedModification::class);

    admin()->recordVerification('calendar', 'nweh@1.0.0', VerificationStatus::Disputed, systemActor(), ctx(), 'Two custodians disagree on the anchor.');

    expect(array_map(fn ($v) => $v->status->value, app(AuditTrail::class)->verifications('', 'calendar', 'nweh@1.0.0')))->toBe(['submitted', 'under-review', 'disputed'])
        ->and(app(AuditTrail::class)->verifications('acme', 'calendar', 'nweh@1.0.0'))->toBe([]);
});

it('keeps an append-only revision log', function () {
    publishCalendar(cyclicCalendar('logged'));
    $log = app(AuditTrail::class)->revisions('', 'calendar', 'logged');

    expect(array_map(fn ($e) => $e->action, $log))->toBe(['draft-saved', 'submitted', 'approved', 'published'])
        ->and($log[3]->actor)->toBe('tests')
        ->and(fn () => RevisionLogRecord::query()->firstOrFail()->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => RevisionLogRecord::query()->firstOrFail()->delete())->toThrow(LogicException::class);
});

it('dispatches scalar-only events after commit', function () {
    Event::fake();

    publishCalendar(cyclicCalendar('evented'), 'acme');
    admin()->saveObservance(['id' => 'o', 'calendar' => 'evented', 'name' => 'O', 'category' => 'custom', 'rules' => [['type' => 'fixed-date', 'parameters' => ['month' => 1, 'day' => 1]]]], systemActor(), ctx('acme'));
    admin()->recordVerification('observance', 'evented/o', VerificationStatus::Submitted, systemActor(), ctx('acme'));

    Event::assertDispatched(CalendarDraftSaved::class, fn ($e) => $e->tenant === 'acme' && $e->calendar === 'evented');
    Event::assertDispatched(CalendarVersionPublished::class, fn ($e) => $e->tenant === 'acme' && $e->version === '1.0.0' && $e->superseded === null);
    Event::assertDispatched(ObservancesChanged::class, fn ($e) => $e->observance === 'o' && $e->action === 'saved');
    Event::assertDispatched(VerificationRecorded::class, fn ($e) => $e->status === 'submitted' && $e->previous === null);
});

it('rolls back the audit entry when the write fails', function () {
    publishCalendar(cyclicCalendar('once'));
    $before = RevisionLogRecord::query()->count();

    expect(fn () => admin()->publish('once', '1.0.0', systemActor(), ctx()))->toThrow(ImmutableVersion::class)
        ->and(RevisionLogRecord::query()->count())->toBe($before);
});

it('caches misses and never serves a stale miss or a stale workflow status', function () {
    $wf = app(CalendarWorkflowService::class);
    expect(fn () => NwehMundani::calendar('ghost'))->toThrow(CalendarNotFound::class);

    DB::enableQueryLog();
    expect(fn () => NwehMundani::calendar('ghost'))->toThrow(CalendarNotFound::class);
    expect(count(DB::getQueryLog()))->toBe(0); // the miss came from the cache
    DB::disableQueryLog();

    $wf->saveDraft(cyclicCalendar('ghost'), systemActor());
    expect(NwehMundani::calendar('ghost', '1.0.0', includeInactive: true)->definition()->version->status->value)->toBe('draft');

    $wf->submitForReview('ghost', '1.0.0', systemActor());
    expect(NwehMundani::calendar('ghost', '1.0.0')->definition()->version->status->value)->toBe('under-review');

    $wf->approve('ghost', '1.0.0', systemActor());
    $wf->publish('ghost', '1.0.0', systemActor());
    expect(NwehMundani::calendar('ghost')->version())->toBe('1.0.0');
});
