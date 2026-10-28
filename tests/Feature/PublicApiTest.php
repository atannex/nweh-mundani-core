<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Dates\CalendarDateComparison;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\Capability;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Drivers\DriverKind;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Exceptions\DuplicateCalendar;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;
use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Kernel\Calendar;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\CalendarRegistry;
use Atannex\NwehMundani\Core\Kernel\CalendarResolver;
use Atannex\NwehMundani\Core\Localization\LocalizationProvider;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Observances\ObservanceService;
use Atannex\NwehMundani\Core\Tests\Fixtures\ExtraCalendars;
use Atannex\NwehMundani\Core\Tests\Fixtures\WeekdayDriver;

it('supports the documented API', function () {
    $calendar = NwehMundani::calendar('nweh');
    $date = $calendar->fromCivil('2026-10-28');

    expect($date->cycle('traditional-days')->label())->toBe('Amina')
        ->and(NwehMundani::compare('2026-10-28', ['nweh', 'mundae-wabane', 'mmouck', 'mmock']))->toBeInstanceOf(CalendarDateComparison::class)
        ->and(array_keys(NwehMundani::calendars()))->toBe(['mmock', 'mmouck', 'mundae-wabane', 'nweh'])
        ->and(NwehMundani::calendars())->each->toBeInstanceOf(Calendar::class)
        ->and($calendar->capabilities()->has(Capability::FromCivil))->toBeTrue()
        ->and($calendar->capabilities()->has(Capability::ToCivil))->toBeFalse()
        ->and($calendar->observancesOn('2026-10-28'))->toBe([]);
});

it('throws for unknown calendars and cycles', function () {
    expect(fn () => NwehMundani::calendar('atlantis'))->toThrow(CalendarNotFound::class)
        ->and(fn () => NwehMundani::calendar('nweh')->fromCivil('2026-10-28')->cycle('months'))->toThrow(UnsupportedOperation::class)
        ->and(fn () => NwehMundani::compare('2026-10-28', ['nweh', 'atlantis']))->toThrow(CalendarNotFound::class);
});

it('refuses reverse conversion for a cycle-only calendar, and says why', function () {
    expect(fn () => NwehMundani::calendar('nweh')->toCivil(['position' => 'ankoah']))
        ->toThrow(UnsupportedOperation::class, 'does not determine a single civil date');
});

it('answers cycle queries instead', function () {
    $calendar = NwehMundani::calendar('nweh');

    expect((string) $calendar->nextOccurrence('ankoah', '2026-10-28'))->toBe('2026-11-02')
        ->and((string) $calendar->nextOccurrence('Ankoah', '2026-11-02', inclusive: false))->toBe('2026-11-10')
        ->and((string) $calendar->previousOccurrence('alung', '2026-10-01'))->toBe('2026-09-30')
        ->and(count($calendar->occurrencesBetween('amina', '2026-01-01', '2026-12-31')))->toBe(46)
        ->and($calendar->periodContaining('2026-10-28')->toArray())->toMatchArray(['start' => '2026-10-25', 'end' => '2026-11-01']);
});

it('reports out-of-range dates explicitly', function () {
    NwehMundani::register(cyclicCalendar('bounded', overrides: ['effective' => ['from' => '2026-01-01', 'until' => '2026-12-31']]));
    $calendar = NwehMundani::calendar('bounded');

    expect($calendar->convert('2027-01-01')->status)->toBe(ConversionStatus::OutOfRange)
        ->and(fn () => $calendar->fromCivil('2027-01-01'))->toThrow(DateOutOfRange::class)
        ->and(NwehMundani::compare('2027-01-01', ['nweh', 'bounded'])->labels())->toBe(['nweh' => 'Afeah', 'bounded' => null]); // 92 days on: 92 mod 8 = 4
});

it('registers custom calendars of any length without touching package code', function () {
    NwehMundani::register([
        'id' => 'ten-day',
        'name' => ['original' => 'Ten-day cycle', 'language' => 'en'],
        'cycles' => [[
            'id' => 'decade',
            'positions' => array_map(fn ($i) => ['id' => "d{$i}", 'label' => "Day {$i}"], range(1, 10)),
            'anchors' => [['id' => 'start', 'date' => '2026-01-01', 'position' => 'd1']],
        ]],
    ]);

    expect(NwehMundani::calendar('ten-day')->fromCivil('2026-01-11')->cycle('decade')->label())->toBe('Day 1')
        ->and(NwehMundani::has('ten-day'))->toBeTrue();
});

it('registers calendars from config and from definition providers', function () {
    config()->set('nweh-mundani.calendars', [cyclicCalendar('from-config', 6)]);
    config()->set('nweh-mundani.providers', [ExtraCalendars::class]);
    app()->forgetInstance(CalendarRegistry::class);
    app()->forgetInstance(CalendarResolver::class);
    app()->forgetInstance(ObservanceService::class);
    app()->forgetInstance(CalendarManager::class);
    app()->forgetInstance('nweh-mundani.static-observances');
    NwehMundani::clearResolvedInstances();

    expect(NwehMundani::has('from-config'))->toBeTrue()
        ->and(NwehMundani::calendar('five-day')->observancesOn('2026-01-06'))->toHaveCount(1)
        ->and(NwehMundani::calendar('five-day')->observancesOn('2026-01-07'))->toBe([]);
});

it('treats identical re-registration as a no-op and conflicting content as an error', function () {
    NwehMundani::register(cyclicCalendar('dup'));
    NwehMundani::register(cyclicCalendar('dup'));

    expect(fn () => NwehMundani::register(cyclicCalendar('dup', anchorDate: '2026-10-02')))->toThrow(DuplicateCalendar::class);
});

it('refuses to register invalid or draft definitions', function () {
    expect(fn () => NwehMundani::register(cyclicCalendar('conflict', cycle: ['anchors' => [
        ['id' => 'a', 'date' => '2026-10-01', 'position' => 'p1'],
        ['id' => 'b', 'date' => '2026-10-01', 'position' => 'p2'],
    ]])))->toThrow(InvalidDefinition::class)
        ->and(fn () => NwehMundani::register(cyclicCalendar('draft', overrides: ['status' => 'draft'])))->toThrow(InvalidDefinition::class);
});

it('resolves the newest version unless pinned, and any version on request', function () {
    NwehMundani::register(cyclicCalendar('versioned'));
    NwehMundani::register(cyclicCalendar('versioned', anchorDate: '2026-10-02', overrides: ['version' => '1.10.0']));
    NwehMundani::register(cyclicCalendar('versioned', anchorDate: '2026-10-03', overrides: ['version' => '1.2.0']));

    expect(NwehMundani::calendar('versioned')->version())->toBe('1.10.0')
        ->and(NwehMundani::calendar('versioned', '1.0.0')->fromCivil('2026-10-01')->label())->toBe('P1');
});

it('registers third-party drivers and exposes only the capabilities they implement', function () {
    NwehMundani::extend(WeekdayDriver::class);
    NwehMundani::register(['id' => 'weekdays', 'name' => 'Weekdays', 'driver' => 'iso-weekday']);
    $calendar = NwehMundani::calendar('weekdays');

    expect($calendar->fromCivil('2026-10-28')->component('weekday'))->toBe(3)
        ->and($calendar->capabilities()->toArray())->toBe(['from-civil'])
        ->and(fn () => $calendar->nextOccurrence('x', '2026-10-28'))->toThrow(UnsupportedOperation::class);
});

it('refuses unsafe driver registrations', function () {
    expect(fn () => NwehMundani::extend(stdClass::class))->toThrow(UnsafeRegistration::class)
        ->and(fn () => NwehMundani::extend('App\\Missing'))->toThrow(UnsafeRegistration::class)
        // Hijacking the built-in key needs an explicit $replace.
        ->and(fn () => NwehMundani::extend(new class implements CalendarDriver
        {
            public function key(): string
            {
                return 'cyclic';
            }

            public function kind(): DriverKind
            {
                return DriverKind::Custom;
            }

            public function version(): string
            {
                return '1';
            }
        }))->toThrow(UnsafeRegistration::class);
});

it('picks up tagged drivers', function () {
    app()->tag([WeekdayDriver::class], 'nweh-mundani.drivers');
    app()->forgetInstance(DriverRegistry::class);

    expect(app(DriverRegistry::class)->has('iso-weekday'))->toBeTrue();
});

it('formats dates with literal placeholders and localisation providers', function () {
    NwehMundani::addLocalizationProvider(new class implements LocalizationProvider
    {
        public function translate(Term $term, string $key, string $locale): ?string
        {
            return $locale === 'xx' && str_ends_with($key, '.position.amina') ? 'Translated' : null;
        }
    });

    $calendar = NwehMundani::calendar('nweh');
    $date = $calendar->fromCivil('2026-10-28');

    expect($calendar->format($date, '{cycle:traditional-days} ({cycle:traditional-days:ordinal}/{cycle:traditional-days:length}) {civil}'))->toBe('Amina (4/8) 2026-10-28')
        ->and($calendar->format($date, '{label} / {cycle:traditional-days:original}', 'xx'))->toBe('Translated / Amina')
        ->and($calendar->format($date, '{unknown} {php}'))->toBe('{unknown} {php}');
});

it('bulk-converts ranges', function () {
    $range = NwehMundani::calendar('mmock')->range('2026-10-01', '2026-10-08');

    expect(array_map(fn ($r) => $r->date?->label(), $range))->toHaveCount(8)
        ->and(end($range)->date?->label())->toBe('Télǎng');
});

it('accepts position names in any Unicode composition as well as identifiers', function () {
    $calendar = NwehMundani::calendar('mundae-wabane');
    $decomposed = "Ke\u{0300}lu\u{0327}"; // the supplied spelling uses precomposed è (U+00E8)

    expect((string) $calendar->nextOccurrence($decomposed, '2026-10-01'))->toBe('2026-10-04')
        ->and((string) $calendar->nextOccurrence('kelu', '2026-10-01'))->toBe('2026-10-04')
        ->and(fn () => $calendar->nextOccurrence('Kelu', '2026-10-01'))->toThrow(UnsupportedOperation::class);
})->skip(! class_exists(Normalizer::class), 'needs ext-intl');
