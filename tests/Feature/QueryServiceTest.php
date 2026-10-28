<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Application\CalendarViewBuilder;
use Atannex\NwehMundani\Core\Application\Data\CalendarMonthData;
use Atannex\NwehMundani\Core\Application\TranslationManagementService;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\RangeTooLarge;
use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\Violation;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Tests\Fixtures\CalendarController;
use Illuminate\Support\Facades\Route;

function queries(): CalendarQueryService
{
    return app(CalendarQueryService::class);
}

/** Register a code-level observance (published unless given). */
function staticObservance(array $data): void
{
    app('nweh-mundani.static-observances')->save(app(ObservanceFactory::class)->fromArray([
        'name' => 'Observance', 'category' => 'market', ...$data,
    ]));
}

it('builds a month a frontend can render without date maths', function () {
    $month = NwehMundani::calendar('nweh')->month(2026, 10);

    expect($month)->toBeInstanceOf(CalendarMonthData::class)
        ->and($month->days)->toHaveCount(31)
        ->and($month->start)->toBe('2026-10-01')
        ->and($month->end)->toBe('2026-10-31')
        ->and($month->firstIsoWeekday)->toBe(4)            // 1 October 2026 is a Thursday
        ->and($month->day(1)?->label())->toBe('Ankoah')
        ->and($month->day(28)?->label())->toBe('Amina')     // 27 days on: 27 mod 8 = 3 → 4th
        ->and($month->day(31)?->label())->toBe('Aseih')     // 30 days on: 30 mod 8 = 6 → 7th
        ->and($month->day(1)?->certainty)->toBe('recorded')
        ->and($month->day(2)?->certainty)->toBe('assumed')
        ->and($month->previous)->toBe(['year' => 2026, 'month' => 9])
        ->and($month->next)->toBe(['year' => 2026, 'month' => 11])
        ->and($month->calendar->version)->toBe('1.0.0')
        ->and($month->calendar->verification)->toBe('submitted')
        ->and($month->calendar->unverifiedAssumptions[0]['id'])->toBe('continuous-repetition');
});

it('serialises to a stable, presentation-free array', function () {
    $json = json_encode(queries()->month('mundae-wabane', 2026, 10), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['calendar', 'year', 'month', 'start', 'end', 'days_in_month', 'first_iso_weekday', 'days', 'navigation'])
        ->and(array_keys($data['days'][0]))->toBe(['calendar', 'version', 'date', 'iso_weekday', 'status', 'certainty', 'label', 'cycles', 'occurrences', 'exceptions', 'components', 'units', 'notes'])
        ->and($data['days'][0]['cycles'][0]['original'])->toBe("Agh\u{00E0}\u{0327}")
        ->and($data['days'][3]['label'])->toBe("K\u{00E8}lu\u{0327}")
        ->and($json)->not->toContain('<')
        ->and($json)->not->toContain('class');
});

it('builds a year in one pass with correct month lengths', function () {
    $year = queries()->year('nweh', 2026);

    expect($year->months)->toHaveCount(12)
        ->and(array_sum(array_map(fn ($m) => count($m->days), $year->months)))->toBe(365)
        ->and($year->month(2)?->days)->toHaveCount(28)
        ->and($year->month(1)?->day(1)?->label())->toBe('Alung')   // −273 days: −273 mod 8 = 7 → 8th
        ->and($year->month(12)?->day(31)?->label())->toBe('Amina') // 91 days: 91 mod 8 = 3 → 4th
        ->and($year->isLeapYear)->toBeFalse()
        ->and(queries()->year('nweh', 2028)->month(2)?->days)->toHaveCount(29)
        ->and($year->toArray()['months'][0])->not->toHaveKey('calendar');
});

it('resolves a single day, a range and its periods', function () {
    $day = queries()->day('mmock', '2026-10-04');

    expect($day->label())->toBe('Ngong')
        ->and($day->cycle('traditional-days')?->ordinal)->toBe(4)
        ->and(queries()->range('mmouck', '2026-09-29', '2026-10-02')->days)->toHaveCount(4)
        ->and(array_map(fn ($p) => $p->range->start->toString(), queries()->periods('nweh', '2026-10-01', '2026-10-20')))->toBe(['2026-10-01', '2026-10-09', '2026-10-17']);
});

it('compares calendars on a day and over a range without merging them', function () {
    $comparison = queries()->compare('2026-10-04', ['mmouck', 'mmock']);

    expect($comparison->labels())->toBe(['mmouck' => 'Njœêngong', 'mmock' => 'Ngong'])
        ->and(array_keys($comparison->toArray()['calendars']))->toBe(['mmouck', 'mmock']);

    $range = queries()->compareRange('2026-10-01', '2026-10-03', ['nweh', 'mmock']);
    expect($range)->toHaveCount(3)
        ->and($range[2]->labels())->toBe(['nweh' => 'Alena', 'mmock' => 'Mbeqlěq']);
});

it('caps request-sized ranges', function () {
    config()->set('nweh-mundani.queries.max_range_days', 40);
    app()->forgetInstance(CalendarQueryService::class);
    app()->forgetInstance(CalendarViewBuilder::class);
    app()->forgetInstance(CalendarManager::class);

    expect(fn () => queries()->range('nweh', '2026-01-01', '2026-12-31'))->toThrow(RangeTooLarge::class)
        ->and(fn () => queries()->observances('nweh', '2026-01-01', '2026-12-31'))->toThrow(RangeTooLarge::class)
        ->and(queries()->month('nweh', 2026, 10)->days)->toHaveCount(31);
});

it('lists calendars and details them, with sources and validation', function () {
    expect(array_map(fn ($c) => $c->id, queries()->listCalendars()))->toBe(['mmock', 'mmouck', 'mundae-wabane', 'nweh']);

    $details = queries()->getCalendar('mmouck')->toArray();

    expect($details['cycles'][0]['positions'][3]['original'])->toBe('Njœêngong')
        ->and($details['sources'][0]['id'])->toBe('atannex-maintainer-2026-10')
        ->and($details['validation']['valid'])->toBeTrue()
        ->and($details['scope'])->toBe('global')
        ->and(queries()->capabilities('nweh'))->toContain('from-civil')
        ->and(queries()->cycles('nweh')[0]->length)->toBe(8);
});

it('reports occurrences with cancellations and reschedules, generated on demand', function () {
    NwehMundani::register(cyclicCalendar('town'));
    staticObservance([
        'id' => 'market', 'calendar' => 'town', 'name' => 'Market',
        'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']]]],
        'overrides' => [
            ['date' => '2026-10-09', 'action' => 'cancel', 'reason' => 'Funeral'],
            ['date' => '2026-10-17', 'action' => 'reschedule', 'rescheduled_to' => '2026-10-18'],
        ],
    ]);

    $occurrences = array_map(fn ($o) => [$o->date, $o->scheduledFor, $o->state], queries()->observances('town', '2026-10-01', '2026-10-31'));

    expect($occurrences)->toBe([
        ['2026-10-01', '2026-10-01', 'scheduled'],
        ['2026-10-09', '2026-10-09', 'cancelled'],
        ['2026-10-18', '2026-10-17', 'rescheduled'],
        ['2026-10-25', '2026-10-25', 'scheduled'],
    ])
        ->and(queries()->observances('town', '2026-10-01', '2026-10-31', includeCancelled: false))->toHaveCount(3)
        ->and(NwehMundani::calendar('town')->observancesOn('2026-10-09'))->toBe([])
        ->and(NwehMundani::calendar('town')->observancesOn('2026-10-17'))->toBe([])
        ->and(NwehMundani::calendar('town')->observancesOn('2026-10-18'))->toHaveCount(1)
        ->and(queries()->day('town', '2026-10-18')->occurrences[0]->state)->toBe('rescheduled');
});

it('hides draft and cancelled observances from readers', function () {
    NwehMundani::register(cyclicCalendar('town'));
    staticObservance(['id' => 'draft', 'calendar' => 'town', 'status' => 'draft', 'rules' => [['type' => 'fixed-date', 'parameters' => ['date' => '2026-10-01']]]]);
    staticObservance(['id' => 'gone', 'calendar' => 'town', 'status' => 'cancelled', 'rules' => [['type' => 'fixed-date', 'parameters' => ['date' => '2026-10-01']]]]);

    expect(queries()->day('town', '2026-10-01')->occurrences)->toBe([])
        ->and(queries()->searchEvents())->toBe([]);
});

it('searches events by accent-insensitive name, category and window', function () {
    NwehMundani::register(cyclicCalendar('town'));
    staticObservance(['id' => 'big', 'calendar' => 'town', 'name' => 'Grand Marché', 'category' => 'market', 'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']]]]]);
    staticObservance(['id' => 'fest', 'calendar' => 'town', 'name' => 'Harvest', 'category' => 'festival', 'rules' => [['type' => 'fixed-date', 'parameters' => ['month' => 12, 'day' => 20]]]]);

    expect(array_map(fn ($o) => $o->observance->id, queries()->searchEvents('marche')))->toBe(['big'])
        ->and(array_map(fn ($o) => $o->observance->id, queries()->searchEvents(categories: ['festival'])))->toBe(['fest'])
        ->and(array_map(fn ($o) => $o->observance->id, queries()->searchEvents(from: '2026-10-01', to: '2026-10-05')))->toBe(['big'])
        ->and(queries()->searchEvents(from: '2026-10-01', to: '2026-10-05')[0]->occurrences)->toHaveCount(1);
});

it('registers translations without republishing, per tenant, keeping the original', function () {
    $translations = app(TranslationManagementService::class);
    $key = 'calendar.nweh.cycle.traditional-days.position.amina';

    $translations->register('nweh', $key, 'fr', 'Jour quatre', new SystemActor);
    $translations->register('nweh', $key, 'fr', 'Quatrième (tenant)', new SystemActor, new CalendarContext('acme'));

    $fr = fn (?string $tenant) => queries()->day('nweh', '2026-10-28', new CalendarContext($tenant, 'fr'))->cycles[0];

    expect($fr(null)->label)->toBe('Jour quatre')
        ->and($fr(null)->original)->toBe('Amina')
        ->and($fr('acme')->label)->toBe('Quatrième (tenant)')
        ->and($fr('rival')->label)->toBe('Jour quatre')
        // The version itself is untouched: its term carries no translations.
        ->and(NwehMundani::calendar('nweh')->definition()->cycles[0]->positions[3]->label->translations)->toBe([])
        ->and(queries()->translations('nweh')->locales())->toBe(['fr'])
        ->and(fn () => $translations->register('nweh', 'calendar.nweh.cycle.traditional-days.position.nope', 'fr', 'x', new SystemActor))->toThrow(InvalidDefinition::class)
        ->and(fn () => $translations->register('nweh', $key, 'fr', 'x', null))->toThrow(UnauthorizedModification::class);
});

it('keeps diagnostics behind an ability', function () {
    expect(fn () => queries()->diagnostics(null))->toThrow(UnauthorizedModification::class)
        ->and(queries()->diagnostics(new SystemActor))->not->toBeEmpty();
});

it('gives exceptions stable machine-readable keys', function () {
    try {
        queries()->month('atlantis', 2026, 10);
    } catch (CalendarNotFound $e) {
        expect($e->errorKey())->toBe('calendar-not-found')
            ->and($e->toArray())->toHaveKeys(['error', 'message']);
    }

    expect((new InvalidDefinition('x', [new Violation('a', 'b')]))->toArray()['violations'])->toBe([['path' => 'a', 'message' => 'b']]);
});

it('serves a Blade page and a JSON API through a thin controller', function () {
    $this->app['view']->addNamespace('fixtures', __DIR__.'/../Fixtures/views');
    Route::get('/calendars/{calendar}/{year}/{month}', [CalendarController::class, 'month']);
    Route::get('/api/calendars/{calendar}/{year}/{month}', [CalendarController::class, 'api']);

    $this->get('/calendars/mmock/2026/10')->assertOk()->assertSee('Mmock 2026-10')->assertSee('<li data-date="2026-10-04">Ngong</li>', false);
    $this->getJson('/api/calendars/nweh/2026/10')->assertOk()->assertJsonPath('days.27.label', 'Amina')->assertJsonPath('calendar.id', 'nweh');
    $this->getJson('/api/calendars/atlantis/2026/10')->assertNotFound()->assertJsonPath('error', 'calendar-not-found');
});
