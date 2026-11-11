# Nweh Mundani Core

**Nweh Mundani Core** is a calendar engine for Laravel applications. It provides the tools needed to define calendars, work with dates, manage traditional day cycles, calculate recurring events, and store calendar information.

The package was developed with particular attention to indigenous calendars, including the traditional calendars of the Nweh and Mundani people of Lebialem, Cameroon.

Unlike conventional calendar systems, these calendars do not necessarily follow seven-day weeks or twelve-month years. The package supports these differences while also providing support for the Gregorian, Julian, Islamic (tabular), and Hebrew calendars.

Nweh Mundani Core handles the calendar logic and data management. Your Laravel application remains responsible for the user interface and how calendar information is presented.

## 1. Requirements

Before installing the package, make sure your application meets the following requirements:

- PHP 8.3 or later
- Laravel 12 or Laravel 13
- Composer
- PHP `ext-intl` (recommended for accurate handling of accented characters and combining marks)

## 2. Installation

Install the package through Composer:

```bash
composer require atannex/nweh-mundani-core
```

After installation, run the package installation command:

```bash
php artisan nweh-mundani:install
```

This command publishes the package configuration and gives you the option to run the database migrations.

Once installation is complete, run the diagnostic command:

```bash
php artisan nweh-mundani:diagnose
```

This checks the package configuration and helps identify common installation problems.

### Service provider and facade

Laravel automatically discovers the package service provider and facade. You do not need to register them manually.

You can access the package through:

```php
use Atannex\NwehMundani\Core\Facades\NwehMundani;
```

### Database migrations

The package includes its own migrations, which Laravel can run directly.

If you prefer to manage the migrations yourself, publish them:

```bash
php artisan vendor:publish --tag=nweh-mundani-migrations
```

Then disable the package migrations in your `.env` file:

```env
NWEH_MUNDANI_RUN_MIGRATIONS=false
```

This prevents Laravel from running both your published migrations and the package migrations.

### Running without a database

Database storage is optional.

If your application only needs calendar calculations and does not need to save calendar definitions or events, add:

```env
NWEH_MUNDANI_PERSISTENCE=false
```

Calendars registered directly in your application will continue to work.

## 3. Working with the bundled calendars

The package comes with four Lebialem calendar definitions:

| Calendar         | Identifier       |
| ---------------- | ---------------- |
| Nweh             | `nweh`           |
| Mundani (Wabane) | `mundani-wabane` |
| M'Mouck          | `mmouck`         |
| M'Mock           | `mmock`          |

Each calendar uses an eight-day traditional cycle.

To work with a calendar, retrieve it using its identifier:

```php
use Atannex\NwehMundani\Core\Facades\NwehMundani;

$calendar = NwehMundani::calendar('nweh');
```

### Convert a Gregorian date into a traditional date

Use `fromCivil()` to look up a traditional date from a Gregorian calendar date.

```php
$day = $calendar
    ->fromCivil('2026-10-28')
    ->cycle('traditional-days');
```

You can then retrieve information about the traditional day:

```php
$day->label();     // Amina
$day->ordinal();   // 4
$day->certainty;   // Certainty::Assumed
```

The `fromCivil()` method accepts three types of input:

- A date string in `Y-m-d` format.
- A PHP `DateTimeInterface` instance.
- A `CivilDate` instance.

### Understanding date certainty

The package keeps track of how reliable a calendar date is.

For example, `Assumed` means the date was calculated using the available cycle information but has not been confirmed by a recorded historical reference.

This distinction is important when working with traditional calendars whose historical records may be incomplete.

### Preserve traditional names

Calendar names are stored and returned exactly as supplied.

For example, characters such as `œ`, `à`, and combining marks are preserved. A name such as `Njœêngong` will not be automatically converted into a different spelling.

## 4. Comparing dates across calendars

You can retrieve the traditional day names associated with a Gregorian date across all four bundled calendars.

```php
$result = NwehMundani::compare('2026-10-28');

$labels = $result->labels();
```

Example result:

```php
[
    'mmock' => 'Ngong',
    'mmouck' => 'Njœêngong',
    'mundani-wabane' => 'Kèlu̧',
    'nweh' => 'Amina',
]
```

This is useful when building applications that display the same date according to different traditional communities.

## 5. Finding recurring traditional days

The calendar instance provides methods for locating recurring days and retrieving the periods in which they occur.

### Find the next occurrence

```php
$next = $calendar->nextOccurrence(
    'amina',
    '2026-10-29'
);
```

This returns the next occurrence as a `CivilDate`.

### Find all occurrences within a date range

```php
$occurrences = $calendar->occurrencesBetween(
    'amina',
    '2026-10-01',
    '2026-12-31'
);
```

### Find the cycle containing a date

```php
$period = $calendar->periodContaining('2026-10-28');
```

The returned period represents the eight-day cycle containing the requested date.

## 6. Building calendar pages and month views

When developing a calendar interface, you will often need more than the name of a particular day.

The `month()` method provides the information needed to build a calendar month view.

```php
$month = NwehMundani::calendar('nweh')
    ->month(2026, 10);
```

You can access the month information directly:

```php
$month->firstIsoWeekday;
$month->day(28)->label();
$month->day(28)->occurrences;
$month->next;
```

For example:

- `firstIsoWeekday` tells you which ISO weekday the month begins on.
- `day(28)->label()` retrieves the traditional name associated with October 28.
- `day(28)->occurrences` retrieves observances associated with that date.
- `next` provides the year and month of the following month.

The month object can also be returned directly from a Laravel controller as JSON:

```php
public function calendar()
{
    $month = NwehMundani::calendar('nweh')
        ->month(2026, 10);

    return response()->json($month);
}
```

This makes it possible to use the same calendar calculations in Blade, Livewire, Vue, React, or other frontend applications.

The package also provides:

```php
$calendar->year(2026);

$calendar->day('2026-10-28');

$calendar->days('2026-10-01', '2026-10-31');
```

## 7. Registering a custom calendar

You are not limited to the calendars included with the package.

You can register your own calendar from a Laravel service provider. This is useful when implementing another traditional calendar, a community-specific market cycle, or a custom calendar used by your application.

Here is an example of a five-day market calendar:

```php
use Atannex\NwehMundani\Core\Facades\NwehMundani;

NwehMundani::register([
    'id' => 'market-week',
    'version' => '1.0.0',

    'name' => [
        'original' => 'Market week',
        'language' => 'en',
    ],

    'driver' => 'cyclic',

    'cycles' => [
        [
            'id' => 'days',

            'positions' => [
                ['id' => 'first', 'label' => 'First'],
                ['id' => 'second', 'label' => 'Second'],
                ['id' => 'third', 'label' => 'Third'],
                ['id' => 'fourth', 'label' => 'Fourth'],
                ['id' => 'fifth', 'label' => 'Fifth'],
            ],

            'anchors' => [
                [
                    'id' => 'ref',
                    'date' => '2026-01-01',
                    'position' => 'first',
                ],
            ],
        ],
    ],
]);
```

### Understanding the calendar definition

The definition contains several important properties:

| Property    | Purpose                                               |
| ----------- | ----------------------------------------------------- |
| `id`        | Unique identifier for the calendar                    |
| `version`   | Version of the calendar definition                    |
| `name`      | Calendar name and language                            |
| `driver`    | Calculation method used by the calendar               |
| `cycles`    | The cycles that make up the calendar                  |
| `positions` | The individual positions within a cycle               |
| `anchors`   | Known dates used as reference points for calculations |

An anchor establishes a known position on a particular date. The package uses that reference to calculate other dates in the cycle.

Cycles do not have to contain eight days. You can define a cycle of any length, and a calendar can contain multiple cycles.

### Supported calendar drivers

The package supports the following driver types:

- `cyclic` — For calendars built around repeating cycles.
- `solar` — For solar calendar systems.
- `lunar-tabular` — For tabular lunar calendar calculations.
- `lunisolar-hebrew` — For Hebrew lunisolar calendar calculations.

## 8. Saving and managing calendar definitions

When calendars need to be stored in the database, the package provides a workflow for managing their definitions.

This is particularly useful when several people are responsible for creating, reviewing, and publishing calendar information.

A calendar definition moves through four stages:

1. **Draft:** An editor creates or updates a calendar definition.
2. **Review:** The definition is submitted for review.
3. **Approval:** An authorized reviewer approves the definition.
4. **Publication:** The approved version becomes available for use.

Published versions are immutable. If a calendar needs to be changed, create and publish a new version rather than modifying the existing published version.

### Using the calendar workflow

The workflow is available through `CalendarWorkflowService`.

```php
use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;

$workflow = app(CalendarWorkflowService::class);

// Create or save a draft.
$workflow->saveDraft($definition, $editor);

// Submit the definition for review.
$workflow->submitForReview(
    'market-week',
    '1.0.0',
    $editor
);

// Approve the definition.
$workflow->approve(
    'market-week',
    '1.0.0',
    $reviewer
);

// Publish the approved definition.
$workflow->publish(
    'market-week',
    '1.0.0',
    $reviewer
);
```

By default, the person approving a calendar must be different from the person who created it.

You can change this behavior through the environment configuration:

```env
NWEH_MUNDANI_SEPARATE_APPROVER=false
```

To disable the review requirement:

```env
NWEH_MUNDANI_REQUIRE_REVIEW=false
```

### Configuring permissions

The workflow uses Laravel Gates to determine who can manage, review, and publish calendars.

Define the permissions in an appropriate service provider:

```php
use Illuminate\Support\Facades\Gate;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;

Gate::define(
    CalendarAuthorizer::MANAGE_CALENDARS,
    fn ($user, $tenant, $calendar) => $user->isEditor()
);

Gate::define(
    CalendarAuthorizer::REVIEW_CALENDARS,
    fn ($user, $tenant, $calendar) => $user->isReviewer()
);

Gate::define(
    CalendarAuthorizer::PUBLISH_CALENDARS,
    fn ($user, $tenant, $calendar) => $user->isReviewer()
);
```

For background jobs and Artisan commands, you can use a system actor instead of an authenticated user:

```php
use Atannex\NwehMundani\Core\Governance\SystemActor;

$actor = new SystemActor('import');
```

## 9. Managing festivals and events

The package uses the term **observances** for festivals, market days, ceremonies, and other calendar events.

Rather than saving a separate record for every occurrence, observances can be defined using rules. The package calculates their dates when requested.

### Creating an observance

Use `ObservanceManagementService` to create and manage observances.

```php
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;

$observances = app(ObservanceManagementService::class);

$observances->create([
    'id' => 'market',

    'calendar' => 'nweh',

    'name' => 'Market day',

    'category' => 'market',

    'rules' => [
        [
            'type' => 'cycle-position',

            'parameters' => [
                'cycle' => 'traditional-days',
                'positions' => ['amina'],
            ],
        ],
    ],
], $editor, publish: true);
```

The example creates an observance associated with the `amina` position in the Nweh traditional day cycle.

### Retrieving observances for a date

Once an observance has been published, you can retrieve the events associated with a particular date:

```php
$events = NwehMundani::calendar('nweh')
    ->observancesOn('2026-10-28');
```

### Cancelling an individual occurrence

Sometimes an event needs to be cancelled for a particular date without changing its recurring rule.

Use:

```php
$observances->cancelOccurrence(
    'nweh',
    'market',
    '2026-11-05',
    $editor,
    'Funeral in the village'
);
```

### Rescheduling an occurrence

You can also move an individual event to another date:

```php
$observances->rescheduleOccurrence(
    'nweh',
    'market',
    '2026-11-13',
    '2026-11-14',
    $editor
);
```

Other supported observance rule types include:

- `fixed-date`
- `occurrences`
- `recurrence`
- `relative`

These allow you to define events based on fixed dates, specific occurrences, recurring patterns, or relationships with other calendar dates.

## 10. Working with multiple tenants

Nweh Mundani Core supports multi-tenant applications.

You can scope calendar operations to a particular tenant:

```php
$calendar = NwehMundani::forTenant($team->id)
    ->calendar('nweh');
```

Each tenant can access:

- Its own calendars and observances.
- Globally published calendars and observances.

Tenant-specific data is kept separate from other tenants' data.

### Using Spatie Laravel Multitenancy

If your application uses `spatie/laravel-multitenancy`, you can configure the package to resolve the active tenant automatically.

Add the following to your `.env` file:

```env
NWEH_MUNDANI_TENANT_RESOLVER=spatie
```

The package will use Spatie's current tenant during HTTP requests, queued jobs, and `tenants:artisan` commands.

When there is no active tenant, operations use the global scope. Calendars published globally are available to all tenants.

### Shared database setup

If all tenants use the same database, no additional migration configuration is required.

Run your migrations normally:

```bash
php artisan migrate
```

### Separate database per tenant

If your application uses Spatie's `SwitchTenantDatabaseTask` and each tenant has a separate database, the package stores its tables in the landlord database.

This keeps global calendar definitions available to all tenants while the `tenant_key` column maintains tenant separation.

Publish the landlord migrations:

```bash
php artisan vendor:publish --tag=nweh-mundani-landlord-migrations
```

Then run them against your landlord database:

```bash
php artisan migrate \
    --path=database/migrations/landlord \
    --database=landlord
```

The landlord connection is obtained from Spatie's `landlord_database_connection_name` configuration.

If you want to use another connection, configure:

```env
NWEH_MUNDANI_DB_CONNECTION=your_connection
```

Run the diagnostic command to check the database configuration:

```bash
php artisan nweh-mundani:diagnose
```

### Cache isolation

The package maintains its own cache prefix and separates cached calendar information by tenant.

Spatie's `PrefixCacheTask` can therefore be used alongside the package without requiring additional cache configuration.

### Custom tenant resolution

If you are not using Spatie, you can provide your own tenant resolution logic.

Create a class that implements:

```php
Atannex\NwehMundani\Core\Tenancy\TenantResolver
```

Then configure the `tenant_resolver` option in the package configuration to point to your implementation.

## 11. Available Artisan commands

The package includes several Artisan commands for inspecting calendars, checking dates, validating definitions, and diagnosing configuration problems.

| Command                             | Description                                           |
| ----------------------------------- | ----------------------------------------------------- |
| `nweh-mundani:list`                 | Lists registered calendars                            |
| `nweh-mundani:show nweh`            | Displays information about a calendar                 |
| `nweh-mundani:date nweh 2026-10-28` | Displays the traditional date for a Gregorian date    |
| `nweh-mundani:compare 2026-10-28`   | Compares a date across bundled calendars              |
| `nweh-mundani:validate --strict`    | Validates calendar definitions                        |
| `nweh-mundani:diagnose`             | Checks package configuration and installation         |
| `nweh-mundani:seed-examples`        | Copies bundled calendar definitions into the database |

For example, to list the available calendars:

```bash
php artisan nweh-mundani:list
```

To inspect a particular calendar:

```bash
php artisan nweh-mundani:show nweh
```

To compare traditional dates:

```bash
php artisan nweh-mundani:compare 2026-10-28
```

Most commands also support the `--json` option, which is useful when integrating the package with scripts or external tools.

## 12. Important information about the bundled calendars

The bundled Nweh, Mundani, M'Mouck, and M'Mock calendar definitions currently use day names, cycle ordering, and a reference date supplied by the package maintainer.

For these definitions, October 1, 2026 is used as day one of the eight-day cycle.

These details have not yet been independently verified against written historical records or confirmed by community custodians.

For this reason, dates calculated from the current cycle definitions are marked as `Assumed`.

The package distinguishes between three levels of certainty:

| Certainty  | Meaning                                                                      |
| ---------- | ---------------------------------------------------------------------------- |
| `Recorded` | The date is supported by a recorded reference or anchor.                     |
| `Assumed`  | The date is calculated using a cycle whose continuity has not been verified. |
| `Derived`  | The date is calculated from a cycle whose continuity has been verified.      |

Once the continuity of a calendar has been checked against reliable records, its definition can be updated with:

```php
'continuity' => 'verified',
```

This allows calculated dates to be identified as `Derived` rather than `Assumed`.

This distinction is intentional. The package is designed to preserve the difference between a historically recorded date and one calculated from an assumed calendar sequence.

## 13. Development and testing

If you want to contribute to the package or modify its source code, clone the repository and install its development dependencies:

```bash
composer install
```

Run the package checks:

```bash
composer check
```

This runs the configured validation, Laravel Pint, PHPStan, and automated test suite.

## 14. Contributing

Contributions are welcome, particularly in areas involving:

- Additional indigenous calendar systems.
- Historical calendar references and verified date anchors.
- Improvements to calendar calculation drivers.
- Additional observance rules.
- Documentation and automated tests.

When contributing traditional calendar information, please include the relevant source or community reference wherever possible. Preserving the accuracy of indigenous calendar information is an important part of maintaining this package.

## License

See the repository's `LICENSE` file for licensing information.
