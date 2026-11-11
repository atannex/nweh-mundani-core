<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;
use Atannex\NwehMundani\Core\Application\TranslationManagementService;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Illuminate\Support\Facades\DB;

function storeOldWabane(?string $tenant = null): void
{
    $actor = new SystemActor('tests');
    $context = new CalendarContext($tenant);
    $workflow = app(CalendarWorkflowService::class);

    $workflow->saveDraft(cyclicCalendar('mundae-wabane'), $actor, $context);
    $workflow->submitForReview('mundae-wabane', '1.0.0', $actor, context: $context);
    $workflow->approve('mundae-wabane', '1.0.0', $actor, context: $context);
    $workflow->publish('mundae-wabane', '1.0.0', $actor, $context);
}

function renameMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_11_10_000000_rename_mundae_wabane_calendar.php';
}

it('moves the stored mundae-wabane calendar, and what tenants attached to it, to mundani-wabane', function () {
    $actor = new SystemActor('tests');
    $acme = new CalendarContext('acme');
    $key = 'calendar.mundae-wabane.cycle.days.position.p2';

    storeOldWabane();
    app(ObservanceManagementService::class)->create(['id' => 'market', 'calendar' => 'mundae-wabane', 'name' => 'Market', 'category' => 'market',
        'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p2']]]]], $actor, $acme, publish: true);
    app(TranslationManagementService::class)->register('mundae-wabane', $key, 'en', 'Second', $actor, $acme);
    app(CalendarWorkflowService::class)->recordVerification('calendar', 'mundae-wabane@1.0.0', VerificationStatus::Submitted, $actor);

    renameMigration()->up();

    $calendar = NwehMundani::forTenant('acme')->calendar('mundani-wabane');

    expect($calendar->name())->toBe('Mundae-wabane')
        ->and(array_map(fn ($o) => $o->id, $calendar->observancesOn('2026-10-02')))->toBe(['market'])
        ->and(DB::table('nmc_translations')->value('term_key'))->toBe('calendar.mundani-wabane.cycle.days.position.p2')
        ->and(DB::table('nmc_verifications')->value('subject_key'))->toBe('mundani-wabane@1.0.0')
        ->and(DB::table('nmc_revision_logs')->where('subject_key', 'like', '%mundae-wabane%')->count())->toBe(0)
        ->and(DB::table('nmc_revision_logs')->where('subject_key', 'mundani-wabane/calendar.mundani-wabane.cycle.days.position.p2')->count())->toBe(1);

    renameMigration()->down();

    expect(DB::table('nmc_calendars')->value('identifier'))->toBe('mundae-wabane')
        ->and(DB::table('nmc_observances')->value('calendar_identifier'))->toBe('mundae-wabane')
        ->and(DB::table('nmc_translations')->value('term_key'))->toBe($key);
});

it("leaves a tenant's own mundae-wabane alone when renaming would hide it behind the global calendar", function () {
    storeOldWabane('acme');

    renameMigration()->up();

    expect(DB::table('nmc_calendars')->where('tenant_key', 'acme')->value('identifier'))->toBe('mundae-wabane')
        ->and(NwehMundani::forTenant('acme')->calendar('mundae-wabane')->name())->toBe('Mundae-wabane');
});
