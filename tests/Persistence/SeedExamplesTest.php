<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Illuminate\Support\Facades\DB;

it('seeds the four calendars and their source', function () {
    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('published v1.0.0')->assertSuccessful();

    expect(DB::table('nmc_calendars')->orderBy('identifier')->pluck('identifier')->all())->toBe(['mmock', 'mmouck', 'mundae-wabane', 'nweh'])
        ->and(DB::table('nmc_calendar_versions')->where('status', 'published')->count())->toBe(4)
        ->and(DB::table('nmc_cycle_positions')->count())->toBe(32)
        ->and(DB::table('nmc_sources')->value('identifier'))->toBe('atannex-maintainer-2026-10')
        ->and(DB::table('nmc_cycle_positions')->where('label_original', 'Aghà̧')->count())->toBe(1);
});

it('is idempotent', function () {
    $this->artisan('nweh-mundani:seed-examples')->assertSuccessful();
    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('already stored, unchanged')->assertSuccessful();

    expect(DB::table('nmc_calendar_versions')->count())->toBe(4)
        ->and(DB::table('nmc_cycle_positions')->count())->toBe(32)
        ->and(DB::table('nmc_sources')->count())->toBe(1);
});

it('serves the seeded calendars from the database with identical results', function () {
    $before = NwehMundani::compare('2026-10-28')->labels();
    $this->artisan('nweh-mundani:seed-examples')->assertSuccessful();
    config()->set('nweh-mundani.examples.register', false);

    expect(NwehMundani::compare('2026-10-28')->labels())->toBe($before)
        // Stored versions carry a publication time; code-registered ones do not.
        ->and(NwehMundani::calendar('mundae-wabane')->definition()->version->publishedAt)->not->toBeNull();
});

it('never overwrites a calendar that was changed locally', function () {
    $workflow = app(CalendarWorkflowService::class);
    $local = cyclicCalendar('nweh', overrides: ['name' => 'Locally edited Nweh', 'version' => '1.0.1']);
    $workflow->saveDraft($local, new SystemActor, new CalendarContext);
    $workflow->submitForReview('nweh', '1.0.1', new SystemActor, context: new CalendarContext);
    $workflow->approve('nweh', '1.0.1', new SystemActor, context: new CalendarContext);
    $workflow->publish('nweh', '1.0.1', new SystemActor, new CalendarContext);

    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('was not overwritten')->assertSuccessful();

    expect(NwehMundani::calendar('nweh')->name())->toBe('Locally edited Nweh')
        ->and(DB::table('nmc_calendar_versions')->where('status', 'published')->count())->toBe(4);
});

it('writes nothing on a dry run', function () {
    $this->artisan('nweh-mundani:seed-examples --dry-run')->expectsOutputToContain('would publish')->assertSuccessful();

    expect(DB::table('nmc_calendars')->count())->toBe(0);
});
