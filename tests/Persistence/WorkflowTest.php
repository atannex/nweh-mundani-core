<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;
use Atannex\NwehMundani\Core\Application\TranslationManagementService;
use Atannex\NwehMundani\Core\Events\CalendarAnchorCorrected;
use Atannex\NwehMundani\Core\Events\CalendarApproved;
use Atannex\NwehMundani\Core\Events\CalendarRejected;
use Atannex\NwehMundani\Core\Events\CalendarStatusChanged;
use Atannex\NwehMundani\Core\Events\CalendarSubmittedForReview;
use Atannex\NwehMundani\Core\Events\CalendarSuperseded;
use Atannex\NwehMundani\Core\Events\CalendarVersionPublished;
use Atannex\NwehMundani\Core\Events\ObservanceCreated;
use Atannex\NwehMundani\Core\Events\ObservanceOccurrenceChanged;
use Atannex\NwehMundani\Core\Events\ObservancePublished;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\CalendarUnavailable;
use Atannex\NwehMundani\Core\Exceptions\DuplicateCalendar;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Exceptions\TenantViolation;
use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CalendarVersionRecord;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

function wf(): CalendarWorkflowService
{
    return app(CalendarWorkflowService::class);
}

function obs(): ObservanceManagementService
{
    return app(ObservanceManagementService::class);
}

function sys(): SystemActor
{
    return new SystemActor('tests');
}

function user(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

/** Editor 1, reviewer 2, publisher 3; abilities keyed by user id, tenant-scoped to $tenant. */
function roles(string $tenant = ''): void
{
    $grant = static fn (array $ids) => static fn (User $u, string $t) => $t === $tenant && in_array($u->id, $ids, true);
    Gate::define(CalendarAuthorizer::MANAGE_CALENDARS, $grant([1]));
    Gate::define(CalendarAuthorizer::REVIEW_CALENDARS, $grant([1, 2]));
    Gate::define(CalendarAuthorizer::PUBLISH_CALENDARS, $grant([3]));
    Gate::define(CalendarAuthorizer::ARCHIVE_CALENDARS, $grant([3]));
}

function release(string $id, string $version = '1.0.0', ?string $tenant = null, array $calendar = []): void
{
    $ctx = new CalendarContext($tenant);
    wf()->saveDraft(cyclicCalendar($id, overrides: ['version' => $version, ...$calendar]), sys(), $ctx);
    wf()->submitForReview($id, $version, sys(), context: $ctx);
    wf()->approve($id, $version, sys(), context: $ctx);
    wf()->publish($id, $version, sys(), $ctx);
}

it('runs draft → review → approval → publication with separate duties', function () {
    roles();
    Event::fake();

    wf()->createCalendar(cyclicCalendar('town'), user(1));
    wf()->submitForReview('town', '1.0.0', user(1), 'Ready');

    expect(fn () => wf()->approve('town', '1.0.0', user(1)))->toThrow(UnauthorizedModification::class, 'you submitted yourself')
        ->and(fn () => wf()->approve('town', '1.0.0', user(3)))->toThrow(UnauthorizedModification::class)
        ->and(fn () => wf()->publish('town', '1.0.0', user(3)))->toThrow(InvalidTransition::class);

    wf()->approve('town', '1.0.0', user(2), 'Checked against the source');
    $result = wf()->publish('town', '1.0.0', user(3));

    $versions = app(CalendarQueryService::class)->history('town', viewer: sys())->versions;

    expect($result->definition->version->publishedAt)->not->toBeNull()
        ->and(NwehMundani::calendar('town')->version())->toBe('1.0.0')
        ->and($versions[0])->toMatchArray(['status' => 'published', 'submitted_by' => 'user:1', 'reviewed_by' => 'user:2', 'published_by' => 'user:3', 'review_notes' => 'Checked against the source']);

    Event::assertDispatched(CalendarSubmittedForReview::class);
    Event::assertDispatched(CalendarApproved::class, fn ($e) => $e->actor === 'user:2');
    Event::assertDispatched(CalendarVersionPublished::class);
});

it('freezes versions under review and unfreezes them on rejection', function () {
    // A blanket fake: the immutability guards must not depend on model events.
    Event::fake();
    wf()->saveDraft(cyclicCalendar('town'), sys(), new CalendarContext);
    wf()->submitForReview('town', '1.0.0', sys());

    expect(fn () => wf()->saveDraft(cyclicCalendar('town', length: 5), sys()))->toThrow(ImmutableVersion::class)
        ->and(fn () => wf()->addAnchor('town', '1.0.0', 'days', ['id' => 'x', 'date' => '2026-10-09', 'position' => 'p1'], sys()))->toThrow(ImmutableVersion::class)
        ->and(fn () => CalendarVersionRecord::query()->firstOrFail()->update(['name_original' => 'Sneaky']))->toThrow(ImmutableVersion::class);

    wf()->reject('town', '1.0.0', sys(), 'Anchor needs a source');
    wf()->saveDraft(cyclicCalendar('town', length: 5), sys());

    expect(NwehMundani::calendar('town', '1.0.0')->definition()->cycles[0]->length())->toBe(5)
        ->and(fn () => wf()->reject('town', '1.0.0', sys(), 'again'))->toThrow(InvalidTransition::class);
    Event::assertDispatched(CalendarRejected::class, fn ($e) => $e->from === 'under-review');
});

it('can allow direct publication of drafts when review is switched off', function () {
    config()->set('nweh-mundani.workflow.require_review', false);

    wf()->saveDraft(cyclicCalendar('quick'), sys());
    wf()->publish('quick', '1.0.0', sys());

    expect(NwehMundani::calendar('quick')->version())->toBe('1.0.0');
});

it('can require sources for publication', function () {
    config()->set('nweh-mundani.workflow.require_sources', true);

    wf()->saveDraft(cyclicCalendar('cited'), sys());
    expect(fn () => wf()->submitForReview('cited', '1.0.0', sys()))->toThrow(InvalidDefinition::class, 'at least one source');

    wf()->saveSource(Source::fromArray(['id' => 'interview-1', 'kind' => 'oral-history', 'title' => 'Interview']), sys());
    wf()->attachSource('cited', '1.0.0', 'interview-1', sys());
    wf()->submitForReview('cited', '1.0.0', sys());

    expect(NwehMundani::calendar('cited', '1.0.0')->definition()->metadata->sources)->toBe(['interview-1']);
});

it('corrects anchors in a new draft and supersedes the published version on release', function () {
    Event::fake();
    release('town');

    $draft = wf()->createDraft('town', sys());
    expect($draft->version->number)->toBe('1.0.1');

    wf()->correctAnchor('town', '1.0.1', 'days', 'a1', ['date' => '2026-10-02'], sys());
    wf()->addException('town', '1.0.1', 'days', ['id' => 'fix', 'date' => '2026-12-24', 'position' => 'p3', 'reason' => 'Recorded'], sys());
    wf()->updateCyclePosition('town', '1.0.1', 'days', 'p1', ['original' => 'First', 'translations' => ['fr' => 'Premier']], sys());
    wf()->addCycle('town', '1.0.1', ['id' => 'five', 'positions' => [['id' => 'f1', 'label' => 'F1'], ['id' => 'f2', 'label' => 'F2'], ['id' => 'f3', 'label' => 'F3'], ['id' => 'f4', 'label' => 'F4'], ['id' => 'f5', 'label' => 'F5']], 'anchors' => [['id' => 'f', 'date' => '2026-10-01', 'position' => 'f1']]], sys());

    // Published history is untouched until the draft is released.
    expect(NwehMundani::calendar('town')->fromCivil('2026-10-01')->label())->toBe('P1');

    wf()->submitForReview('town', '1.0.1', sys());
    wf()->approve('town', '1.0.1', sys());
    wf()->publish('town', '1.0.1', sys());

    $calendar = NwehMundani::calendar('town');
    expect($calendar->fromCivil('2026-10-02')->label())->toBe('First')
        ->and($calendar->fromCivil('2026-12-24')->cycle('days')->exceptionId)->toBe('fix')
        ->and($calendar->fromCivil('2026-10-06')->cycle('five')->ordinal())->toBe(1)
        ->and(NwehMundani::calendar('town', '1.0.0')->fromCivil('2026-10-01')->label())->toBe('P1');

    Event::assertDispatched(CalendarAnchorCorrected::class, fn ($e) => $e->previousDate === '2026-10-01' && $e->date === '2026-10-02');
    Event::assertDispatched(CalendarSuperseded::class, fn ($e) => $e->version === '1.0.0' && $e->supersededBy === '1.0.1');
});

it('publishes each version exactly once, keeping a single published version', function () {
    release('race');
    expect(fn () => wf()->publish('race', '1.0.0', sys()))->toThrow(ImmutableVersion::class);

    release('race', '1.1.0');
    expect(DB::table('nmc_calendar_versions')->where('status', 'published')->count())->toBe(1)
        ->and(DB::table('nmc_calendar_versions')->where('status', 'superseded')->count())->toBe(1);
});

it('suspends and archives without falling back to a packaged calendar of the same id', function () {
    Event::fake();
    release('nweh', calendar: ['name' => 'Stored Nweh']);

    wf()->archive('nweh', sys(), 'Replaced by community edition');

    expect(fn () => NwehMundani::calendar('nweh'))->toThrow(CalendarUnavailable::class)
        ->and(array_keys(NwehMundani::calendars()))->not->toContain('nweh')
        ->and(NwehMundani::calendar('nweh', includeInactive: true)->status()->value)->toBe('archived')
        ->and(app(CalendarQueryService::class)->history('nweh')->status)->toBe('archived')
        ->and(array_map(fn ($c) => $c->id, app(CalendarQueryService::class)->listCalendars(includeInactive: true)))->toContain('nweh')
        ->and(fn () => wf()->createDraft('nweh', sys()))->toThrow(InvalidTransition::class);

    wf()->reactivate('nweh', sys());
    expect(NwehMundani::calendar('nweh')->name())->toBe('Stored Nweh')
        ->and(fn () => wf()->suspend('atlantis', sys()))->toThrow(CalendarNotFound::class);

    Event::assertDispatched(CalendarStatusChanged::class, fn ($e) => $e->to === 'archived');
});

it('refuses tenant duplicates of global ids unless shadowing is allowed', function () {
    expect(fn () => wf()->createCalendar(cyclicCalendar('nweh'), sys(), new CalendarContext('acme')))->toThrow(InvalidDefinition::class, 'global calendar already uses');

    wf()->createCalendar(cyclicCalendar('acme-days'), sys(), new CalendarContext('acme'));
    expect(fn () => wf()->createCalendar(cyclicCalendar('acme-days'), sys(), new CalendarContext('acme')))->toThrow(DuplicateCalendar::class);
});

it('keeps every write and read inside its tenant', function () {
    roles('acme');
    release('private-days', tenant: 'acme');
    wf()->createDraft('private-days', sys(), context: new CalendarContext('acme'));

    $rival = new CalendarContext('rival');

    expect(fn () => wf()->submitForReview('private-days', '1.0.1', sys(), context: $rival))->toThrow(CalendarNotFound::class)
        ->and(fn () => wf()->archive('private-days', sys(), context: $rival))->toThrow(CalendarNotFound::class)
        ->and(fn () => app(CalendarQueryService::class)->month('private-days', 2026, 10, $rival))->toThrow(CalendarNotFound::class)
        ->and(fn () => wf()->saveDraft(cyclicCalendar('private-days', overrides: ['version' => '1.0.2']), user(1), $rival))->toThrow(UnauthorizedModification::class)
        ->and(app(CalendarQueryService::class)->month('private-days', 2026, 10, new CalendarContext('acme'))->days)->toHaveCount(31);
});

it('manages observance lifecycle and single occurrences without storing a row per date', function () {
    Event::fake();
    release('town');
    $base = ['id' => 'market', 'calendar' => 'town', 'name' => 'Market', 'category' => 'market', 'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']]]]];

    obs()->create($base, sys());
    expect(NwehMundani::calendar('town')->observancesOn('2026-10-09'))->toBe([]);

    obs()->publish('town', 'market', sys());
    obs()->cancelOccurrence('town', 'market', '2026-10-09', sys(), 'Rain');
    obs()->rescheduleOccurrence('town', 'market', '2026-10-17', '2026-10-18', sys());

    $states = array_map(fn ($o) => [$o->date, $o->state], app(CalendarQueryService::class)->observances('town', '2026-10-01', '2026-10-31'));

    expect($states)->toBe([['2026-10-01', 'scheduled'], ['2026-10-09', 'cancelled'], ['2026-10-18', 'rescheduled'], ['2026-10-25', 'scheduled']])
        ->and(DB::table('nmc_observance_overrides')->count())->toBe(2)
        ->and(fn () => obs()->cancelOccurrence('town', 'market', '2026-10-10', sys()))->toThrow(InvalidDefinition::class, 'does not occur');

    obs()->restoreOccurrence('town', 'market', '2026-10-09', sys());
    obs()->update('town', 'market', ['name' => 'Big market'], sys());

    expect(NwehMundani::calendar('town')->observancesOn('2026-10-09')[0]->name->original)->toBe('Big market')
        ->and(DB::table('nmc_observance_overrides')->count())->toBe(1);

    obs()->cancel('town', 'market', sys());
    expect(NwehMundani::calendar('town')->observancesOn('2026-10-01'))->toBe([])
        ->and(obs()->all('town', sys()))->toHaveCount(1);

    Event::assertDispatched(ObservanceCreated::class);
    Event::assertDispatched(ObservancePublished::class);
    Event::assertDispatched(ObservanceOccurrenceChanged::class, fn ($e) => $e->action === 'reschedule' && $e->rescheduledTo === '2026-10-18');
});

it('stores translations, invalidates their cache and keeps tenants apart', function () {
    $translations = app(TranslationManagementService::class);
    $key = 'calendar.mmock.cycle.traditional-days.position.ngong';
    $label = fn (?string $tenant) => app(CalendarQueryService::class)->day('mmock', '2026-10-04', new CalendarContext($tenant, 'en'))->label();

    expect($label(null))->toBe('Ngong');

    $translations->register('mmock', $key, 'en', 'Fourth day', sys());
    expect($label(null))->toBe('Fourth day');

    $translations->register('mmock', $key, 'en', 'Acme fourth', sys(), new CalendarContext('acme'));
    expect($label('acme'))->toBe('Acme fourth')->and($label('rival'))->toBe('Fourth day');

    $translations->remove('mmock', $key, 'en', sys());
    expect($label(null))->toBe('Ngong')
        ->and(DB::table('nmc_translations')->count())->toBe(1);
});

it('hides restricted sources and audit actors from viewers without abilities', function () {
    wf()->saveSource(Source::fromArray(['id' => 'elder', 'kind' => 'custodian', 'title' => 'Elder interview', 'custodian' => 'Named elder', 'visibility' => 'restricted']), sys());
    release('town', calendar: ['metadata' => ['sources' => ['elder', 'missing-one']]]);

    $anonymous = app(CalendarQueryService::class)->getCalendar('town')->toArray();
    expect($anonymous['sources'])->toBe([])
        ->and($anonymous['hidden_sources'])->toBe(1)
        ->and($anonymous['missing_sources'])->toBe(['missing-one'])
        ->and(json_encode($anonymous))->not->toContain('Named elder');

    Gate::define('nweh-mundani.view-restricted', fn (User $u) => $u->id === 9);
    expect(app(CalendarQueryService::class)->getCalendar('town', viewer: user(9))->toArray()['sources'][0]['custodian'])->toBe('Named elder');

    $history = app(CalendarQueryService::class)->history('town', viewer: user(5));
    expect($history->actorsVisible)->toBeFalse()
        ->and(array_filter(array_column($history->revisions, 'actor')))->toBe([]);

    Gate::define(CalendarAuthorizer::VIEW_AUDIT, fn (User $u) => $u->id === 5);
    expect(array_column(app(CalendarQueryService::class)->history('town', viewer: user(5))->revisions, 'actor'))->toContain('tests');
});

it('builds months and years with a constant number of queries', function () {
    release('town');
    foreach (['a', 'b', 'c'] as $i => $id) {
        obs()->create(['id' => $id, 'calendar' => 'town', 'name' => strtoupper($id), 'category' => 'market', 'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p'.($i + 1)]]]]], sys(), publish: true);
    }

    $count = function (Closure $work): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $work();

        return $n;
    };

    $cold = function (Closure $work) use ($count): int {
        app('cache')->store()->flush();
        app()->forgetScopedInstances();

        return $count($work);
    };

    $day = $cold(fn () => app(CalendarQueryService::class)->day('town', '2026-10-01'));
    $month = $cold(fn () => app(CalendarQueryService::class)->month('town', 2026, 10));
    $year = $cold(fn () => app(CalendarQueryService::class)->year('town', 2027));
    $warm = $count(fn () => app(CalendarQueryService::class)->month('town', 2027, 3));

    // Loading the aggregate costs the same for 1, 31 or 365 days: no query per day.
    // (the first call also pays a one-off, memoised table-existence check)
    expect($month)->toBeLessThanOrEqual($day)
        ->and($year)->toBeLessThanOrEqual($day)
        ->and($day)->toBeLessThanOrEqual(20)
        ->and($warm)->toBe(0)
        ->and(app(CalendarQueryService::class)->year('town', 2027)->month(10)?->day(4)?->occurrences)->not->toBeEmpty(); // 368 days on: p1
});

it('suspends and archives code-registered calendars too, within their own scope only', function () {
    wf()->suspend('mmock', sys(), 'Under community review');

    expect(fn () => NwehMundani::calendar('mmock'))->toThrow(CalendarUnavailable::class)
        ->and(array_keys(NwehMundani::calendars()))->not->toContain('mmock')
        ->and(NwehMundani::calendar('mmock', includeInactive: true)->status()->value)->toBe('suspended')
        ->and(NwehMundani::calendar('mmock', includeInactive: true)->fromCivil('2026-10-04')->label())->toBe('Ngong')
        ->and(DB::table('nmc_calendar_versions')->count())->toBe(0)
        ->and(NwehMundani::calendar('mmouck')->fromCivil('2026-10-04')->label())->toBe('Njœêngong')
        // A tenant cannot switch off a global calendar for everyone.
        ->and(fn () => wf()->archive('nweh', sys(), context: new CalendarContext('acme')))->toThrow(TenantViolation::class);

    wf()->reactivate('mmock', sys());
    expect(NwehMundani::calendar('mmock')->fromCivil('2026-10-04')->label())->toBe('Ngong');
});

it('lets a long-running worker see translations written by another process, even without a cache', function () {
    config()->set('nweh-mundani.cache.enabled', false);
    $label = fn () => app(CalendarQueryService::class)->day('nweh', '2026-10-28', new CalendarContext(null, 'fr'))->label();

    expect($label())->toBe('Amina');

    // Another process writes straight to the table; this worker's services never see the call.
    DB::table('nmc_translations')->insert(['tenant_key' => '', 'calendar_identifier' => 'nweh', 'term_key' => 'calendar.nweh.cycle.traditional-days.position.amina', 'locale' => 'fr', 'text' => 'Quatrième jour', 'created_at' => now(), 'updated_at' => now()]);
    expect($label())->toBe('Amina'); // same request: memoised

    app()->forgetScopedInstances(); // what Laravel does between queued jobs and Octane requests
    expect($label())->toBe('Quatrième jour');
});

it('never shows unreleased versions or review notes to the public', function () {
    roles();
    release('town');
    wf()->createDraft('town', sys());                                         // 1.0.1, unreviewed
    wf()->submitForReview('town', '1.0.1', sys(), 'Internal: anchor disputed by the custodian');
    $q = app(CalendarQueryService::class);

    expect(fn () => $q->getDefinitionVersion('town', '1.0.1'))->toThrow(CalendarNotFound::class)
        ->and(fn () => $q->getDefinitionVersion('town', '1.0.1', viewer: user(7)))->toThrow(CalendarNotFound::class)
        ->and($q->getDefinitionVersion('town', '1.0.1', viewer: user(2))->summary->versionStatus)->toBe('under-review')   // reviewer
        ->and($q->getDefinitionVersion('town', '1.0.0')->summary->versionStatus)->toBe('published')                       // public history
        ->and(array_column($q->history('town')->versions, 'version'))->toBe(['1.0.0'])
        ->and(json_encode($q->history('town')))->not->toContain('disputed')
        ->and(array_column($q->history('town', viewer: user(1))->versions, 'version'))->toBe(['1.0.0', '1.0.1']);
});
