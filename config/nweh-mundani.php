<?php

declare(strict_types=1);
use Atannex\NwehMundani\Core\Governance\GateCalendarAuthorizer;

return [

    /*
    |--------------------------------------------------------------------------
    | Labels and instants
    |--------------------------------------------------------------------------
    |
    | locale: the locale labels render in (null = the original spelling).
    | timezone: used only when a DateTime instant is passed instead of a
    | civil date, to decide which day that instant falls on.
    |
    */

    'locale' => env('NWEH_MUNDANI_LOCALE'),

    'timezone' => env('NWEH_MUNDANI_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | A class implementing Atannex\NwehMundani\Core\Tenancy\TenantResolver.
    | Null keeps everything in the global scope (single tenant).
    |
    */

    'tenant_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Code-registered calendars
    |--------------------------------------------------------------------------
    |
    | calendars: definition arrays (see docs/definitions.md).
    | observances / sources: arrays in the same shape as the repositories store.
    | providers: classes implementing CalendarDefinitionProvider.
    | versions: pin a calendar id to a version instead of the newest one.
    |
    */

    'calendars' => [],

    'observances' => [],

    'sources' => [],

    'providers' => [],

    'versions' => [],

    'examples' => [
        // Register the bundled Nweh, Mundae (Wabane), Mmouck and Mmock definitions.
        'register' => (bool) env('NWEH_MUNDANI_EXAMPLES', true),

        // Register reference Gregorian, Julian, tabular Hijri and Hebrew
        // definitions for the arithmetic drivers (off by default).
        'reference' => (bool) env('NWEH_MUNDANI_REFERENCE_CALENDARS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions (trusted code only)
    |--------------------------------------------------------------------------
    |
    | Class names of extra drivers (CalendarDriver), rule handlers
    | (RuleHandler), observance resolvers (ObservanceResolver) and
    | localization providers (LocalizationProvider). Container tags
    | "nweh-mundani.drivers", ".rule-handlers", ".observance-resolvers",
    | ".localization-providers" and ".providers" work too.
    |
    */

    'drivers' => [],

    'rule_handlers' => [],

    'observance_resolvers' => [],

    'localization_providers' => [],

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | When disabled, everything works from code-registered definitions and
    | in-memory storage; no table is touched. Run the migrations before
    | enabling it on an existing installation.
    |
    */

    'persistence' => [
        'enabled' => (bool) env('NWEH_MUNDANI_PERSISTENCE', true),
        'connection' => env('NWEH_MUNDANI_DB_CONNECTION'),
        'migrations' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Caches definitions and observances loaded from the database. Keys carry
    | the tenant, calendar and a generation token bumped on publication; no
    | cache tags are needed.
    |
    */

    'cache' => [
        'enabled' => (bool) env('NWEH_MUNDANI_CACHE', true),
        'store' => env('NWEH_MUNDANI_CACHE_STORE'),
        'prefix' => env('NWEH_MUNDANI_CACHE_PREFIX', 'nmc'),
        'ttl' => (int) env('NWEH_MUNDANI_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow
    |--------------------------------------------------------------------------
    |
    | require_review: only approved versions can be published
    |   (draft → under-review → approved → published). When false, a draft
    |   can be published directly.
    | separate_approver: the approver must not be the person who submitted.
    | require_sources: publication needs at least one source on record.
    |
    */

    'workflow' => [
        'require_review' => (bool) env('NWEH_MUNDANI_REQUIRE_REVIEW', true),
        'separate_approver' => (bool) env('NWEH_MUNDANI_SEPARATE_APPROVER', true),
        'require_sources' => (bool) env('NWEH_MUNDANI_REQUIRE_SOURCES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy policy
    |--------------------------------------------------------------------------
    |
    | allow_shadowing: may a tenant define a calendar with the same id as a
    | global one, and see its own instead? Off by default: the global
    | calendar wins and such tenant definitions are refused on write.
    |
    */

    'tenancy' => [
        'allow_shadowing' => (bool) env('NWEH_MUNDANI_ALLOW_SHADOWING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query limits
    |--------------------------------------------------------------------------
    |
    | Guard rails for read services that take ranges from requests.
    |
    */

    'queries' => [
        'max_range_days' => (int) env('NWEH_MUNDANI_MAX_RANGE_DAYS', 1830),
        'max_calendars' => (int) env('NWEH_MUNDANI_MAX_CALENDARS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorisation
    |--------------------------------------------------------------------------
    |
    | Class implementing Atannex\NwehMundani\Core\Governance\CalendarAuthorizer.
    | The default asks Laravel's Gate and denies undefined abilities.
    |
    */

    'authorizer' => GateCalendarAuthorizer::class,

];
