<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Illuminate\Support\Facades\DB;

it('seeds the four calendars and their source', function () {
    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('published v1.1.0')->assertSuccessful();

    expect(DB::table('nmc_calendars')->orderBy('identifier')->pluck('identifier')->all())->toBe(['mmock', 'mmouck', 'mundani-wabane', 'nweh'])
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
        ->and(NwehMundani::calendar('mundani-wabane')->definition()->version->publishedAt)->not->toBeNull();
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

/** Store the 1.x bundled M'Mock (v1.0.0, named "Mmock") as $actor. */
function storeOldMmock(string $actor): void
{
    $data = collect((require __DIR__.'/../../resources/definitions/lebialem.php')['calendars'])->firstWhere('id', 'mmock');
    $data = [...$data, 'version' => '1.0.0', 'status' => 'draft', 'name' => ['original' => 'Mmock']];
    $who = new SystemActor($actor);
    $workflow = app(CalendarWorkflowService::class);

    $workflow->saveSource(Source::fromArray((require __DIR__.'/../../resources/definitions/lebialem.php')['sources'][0]), $who);
    $workflow->saveDraft($data, $who);
    $workflow->submitForReview('mmock', '1.0.0', $who);
    $workflow->approve('mmock', '1.0.0', $who);
    $workflow->publish('mmock', '1.0.0', $who);
}

it('upgrades a copy it published itself to the newer bundled version', function () {
    storeOldMmock('nweh-mundani:seed-examples');

    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('(was v1.0.0)')->assertSuccessful();

    expect(NwehMundani::calendar('mmock')->name())->toBe("M'Mock")
        ->and(NwehMundani::calendar('mmock')->version())->toBe('1.1.0')
        ->and(DB::table('nmc_calendar_versions')->where('status', 'superseded')->count())->toBe(1);
});

it('leaves an older copy alone when someone else published it', function () {
    storeOldMmock('an editor');

    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('was not overwritten')->assertSuccessful();

    expect(NwehMundani::calendar('mmock')->name())->toBe('Mmock');
});
