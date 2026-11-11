# Nweh Mundani Core

A calendar engine for Laravel. It can describe and calculate any calendar, including indigenous calendars that don't use seven-day weeks or twelve months, such as the market-day cycles of the Nweh and Mundani people. It also covers the Gregorian, Julian, Islamic (tabular) and Hebrew calendars.

There are no routes or views in here. You get the calculations, storage and a publishing workflow, and your app decides how to show them.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `ext-intl` is recommended for matching names with accents and combining marks

## Install

```bash
composer require atannex/nweh-mundani-core
php artisan migrate
php artisan nweh-mundani:diagnose
```

The service provider and the `NwehMundani` facade are auto-discovered. To change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=nweh-mundani-config
```

If you don't need a database, set `NWEH_MUNDANI_PERSISTENCE=false`. Calendars registered in code keep working.

## Reading a date

Four Lebialem calendars ship with the package: Nweh (`nweh`), Mundani (Wabane) (`mundani-wabane`), M'Mouck (`mmouck`) and M'Mock (`mmock`). Each one is an eight-day cycle.

```php
use Atannex\NwehMundani\Core\Facades\NwehMundani;

$calendar = NwehMundani::calendar('nweh');

$day = $calendar->fromCivil('2026-10-28')->cycle('traditional-days');

$day->label();     // "Amina"
$day->ordinal();   // 4
$day->certainty;   // Certainty::Assumed, because it was worked out, not recorded
```

`fromCivil()` takes a `Y-m-d` string, a `DateTimeInterface` or a `CivilDate`.

Names are stored exactly as written, character for character, so `Njœêngong` or `Aghà̧` come back unchanged.

Compare one day across every calendar:

```php
NwehMundani::compare('2026-10-28')->labels();
// ['mmock' => 'Ngong', 'mmouck' => 'Njœêngong', 'mundani-wabane' => 'Kèlu̧', 'nweh' => 'Amina']
```

Find when a day comes round again:

```php
$calendar->nextOccurrence('amina', '2026-10-29');                 // CivilDate 2026-11-05
$calendar->occurrencesBetween('amina', '2026-10-01', '2026-12-31');
$calendar->periodContaining('2026-10-28');                        // the 8-day run it falls in
```

## Building a month view

`month()` returns everything a calendar page needs, and it serialises to plain JSON for an API or a JavaScript frontend.

```php
$month = NwehMundani::calendar('nweh')->month(2026, 10);

$month->firstIsoWeekday;      // 4 (1 October 2026 is a Thursday)
$month->day(28)->label();     // "Amina"
$month->day(28)->occurrences; // festivals and other events on that day
$month->next;                 // ['year' => 2026, 'month' => 11]

return response()->json($month);
```

`year()`, `day()` and `days($from, $to)` work the same way.

## Adding your own calendar

Register it from a service provider. You don't need to touch the package.

```php
NwehMundani::register([
    'id' => 'market-week',
    'version' => '1.0.0',
    'name' => ['original' => 'Market week', 'language' => 'en'],
    'driver' => 'cyclic',
    'cycles' => [[
        'id' => 'days',
        'positions' => [
            ['id' => 'first', 'label' => 'First'],
            ['id' => 'second', 'label' => 'Second'],
            ['id' => 'third', 'label' => 'Third'],
            ['id' => 'fourth', 'label' => 'Fourth'],
            ['id' => 'fifth', 'label' => 'Fifth'],
        ],
        // A day you know for sure. Everything else is counted from here.
        'anchors' => [['id' => 'ref', 'date' => '2026-01-01', 'position' => 'first']],
    ]],
]);
```

A cycle can be any length, and a calendar can have more than one. The `driver` can also be `solar`, `lunar-tabular` or `lunisolar-hebrew` for year/month/day calendars.

## Saving calendars to the database

Calendars stored in the database are versioned. A version goes through draft, review, approval and publication, and once it's published it can't be edited. To change it you publish a new version.

```php
use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;

$workflow = app(CalendarWorkflowService::class);

$workflow->saveDraft($definition, $editor);
$workflow->submitForReview('market-week', '1.0.0', $editor);
$workflow->approve('market-week', '1.0.0', $reviewer);
$workflow->publish('market-week', '1.0.0', $reviewer);
```

By default the approver has to be someone other than the editor. Set `NWEH_MUNDANI_SEPARATE_APPROVER=false` or `NWEH_MUNDANI_REQUIRE_REVIEW=false` to relax this.

Each step checks a Gate ability, so define the ones you use:

```php
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;

Gate::define(CalendarAuthorizer::MANAGE_CALENDARS, fn ($user, $tenant, $calendar) => $user->isEditor());
Gate::define(CalendarAuthorizer::REVIEW_CALENDARS, fn ($user, $tenant, $calendar) => $user->isReviewer());
Gate::define(CalendarAuthorizer::PUBLISH_CALENDARS, fn ($user, $tenant, $calendar) => $user->isReviewer());
```

From jobs and commands, pass `new SystemActor('import')` instead of a user.

## Festivals and events

Events are called observances. They're defined by rules, not by stored dates, so they're worked out when you ask for them.

```php
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;

$observances = app(ObservanceManagementService::class);

$observances->create([
    'id' => 'market',
    'calendar' => 'nweh',
    'name' => 'Market day',
    'category' => 'market',
    'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'traditional-days', 'positions' => ['amina']]]],
], $editor, publish: true);

NwehMundani::calendar('nweh')->observancesOn('2026-10-28');   // [Market day]

// Cancel or move a single date without touching the rule.
$observances->cancelOccurrence('nweh', 'market', '2026-11-05', $editor, 'Funeral in the village');
$observances->rescheduleOccurrence('nweh', 'market', '2026-11-13', '2026-11-14', $editor);
```

The other rule types are `fixed-date`, `occurrences`, `recurrence` and `relative`.

## Tenants

Every call can be scoped to a tenant. Each tenant sees its own calendars and events plus the global ones, and can't see anyone else's.

```php
NwehMundani::forTenant($team->id)->calendar('nweh');
```

## Artisan commands

```bash
php artisan nweh-mundani:list
php artisan nweh-mundani:show nweh
php artisan nweh-mundani:date nweh 2026-10-28
php artisan nweh-mundani:compare 2026-10-28
php artisan nweh-mundani:validate --strict
php artisan nweh-mundani:diagnose
php artisan nweh-mundani:seed-examples      # copies the bundled calendars into the database
```

Most of them take `--json`.

## About the bundled calendars

The day names, their order, and the start date (1 October 2026 is day 1 in all four) come from the maintainer. They haven't yet been checked against written records or community custodians. Every other date is counted on the assumption that the cycle never broke, which is why it comes back as `Assumed`. A date confirmed by an anchor comes back as `Recorded`. Once the cycle itself has been checked against records, mark it `'continuity' => 'verified'` and the counted dates become `Derived`.

## Development

```bash
composer install
composer check     # validate, Pint, PHPStan and the test suite
```
