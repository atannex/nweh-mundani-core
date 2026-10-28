<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentCalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CalendarVersionRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CyclePositionRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\CycleRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\RevisionLogRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function repo(): CalendarRepository
{
    return app(CalendarRepository::class);
}

it('uses the Eloquent adapter when persistence is enabled', function () {
    expect(repo())->toBeInstanceOf(EloquentCalendarRepository::class)
        ->and(repo()->isAvailable())->toBeTrue();
});

it('stores and reloads a definition exactly, Unicode and all', function () {
    $data = (require __DIR__.'/../../resources/definitions/lebialem.php')['calendars'][1];
    $data['cycles'][0]['positions'][0]['label'] = [
        'original' => 'Aghà̧', 'alternates' => ["Agh\u{0061}\u{0300}\u{0327}"], 'translations' => ['fr' => 'Premier jour'], 'pronunciation' => 'a-gha',
    ];
    $definition = definition([...$data, 'status' => 'draft']);

    repo()->saveDraft($definition);
    $loaded = repo()->findVersion('', 'mundae-wabane', '1.0.0');

    expect($loaded?->contentHash())->toBe($definition->contentHash())
        ->and(bin2hex($loaded->cycles[0]->positions[0]->label->original))->toBe(bin2hex('Aghà̧'))
        ->and($loaded->cycles[0]->positions[0]->label->alternates)->toBe(["Agh\u{0061}\u{0300}\u{0327}"])
        ->and($loaded->cycles[0]->positions[0]->label->label('fr'))->toBe('Premier jour')
        ->and($loaded->version->status)->toBe(VersionStatus::Draft)
        ->and(repo()->findPublished('', 'mundae-wabane'))->toBeNull();
});

it('publishes atomically and supersedes the previous version', function () {
    repo()->saveDraft(definition(cyclicCalendar('cal')));
    repo()->publish('', 'cal', '1.0.0');
    repo()->saveDraft(definition(cyclicCalendar('cal', anchorDate: '2026-10-02', overrides: ['version' => '1.1.0'])));
    $result = repo()->publish('', 'cal', '1.1.0');

    expect($result->superseded)->toBe('1.0.0')
        ->and(repo()->findPublished('', 'cal')?->version->number)->toBe('1.1.0')
        ->and(array_map(fn ($v) => [$v->version, $v->status->value], repo()->versions('', 'cal')))->toBe([['1.0.0', 'superseded'], ['1.1.0', 'published']])
        // The superseded version stays retrievable for history.
        ->and(repo()->findVersion('', 'cal', '1.0.0')?->cycles[0]->anchors[0]->date->toString())->toBe('2026-10-01');
});

it('lets drafts be replaced but freezes published versions', function () {
    repo()->saveDraft(definition(cyclicCalendar('frozen')));
    repo()->saveDraft(definition(cyclicCalendar('frozen', length: 5)));
    expect(repo()->findVersion('', 'frozen', '1.0.0')?->cycles[0]->length())->toBe(5);

    repo()->publish('', 'frozen', '1.0.0');

    expect(fn () => repo()->saveDraft(definition(cyclicCalendar('frozen', length: 7))))->toThrow(ImmutableVersion::class)
        ->and(fn () => repo()->publish('', 'frozen', '1.0.0'))->toThrow(ImmutableVersion::class);
});

it('blocks edits to published versions at the model level too', function () {
    repo()->saveDraft(definition(cyclicCalendar('locked')));
    repo()->publish('', 'locked', '1.0.0');
    $version = CalendarVersionRecord::query()->firstOrFail();

    expect(fn () => $version->update(['name_original' => 'Changed']))->toThrow(ImmutableVersion::class)
        ->and(fn () => $version->delete())->toThrow(ImmutableVersion::class)
        ->and(fn () => CyclePositionRecord::query()->firstOrFail()->update(['label_original' => 'X']))->toThrow(ImmutableVersion::class)
        ->and(fn () => CycleRecord::query()->firstOrFail()->delete())->toThrow(ImmutableVersion::class)
        ->and(CyclePositionRecord::query()->orderBy('ordinal')->first()?->label_original)->toBe('P1');
});

it('keeps published versions and audit rows immutable even with events faked or muted', function () {
    Event::fake();
    repo()->saveDraft(definition(cyclicCalendar('muted')));
    repo()->publish('', 'muted', '1.0.0');
    app(AuditTrail::class)->recordRevision(new RevisionEntry('', 'calendar', 'muted', 'x', null));

    expect(fn () => CalendarVersionRecord::withoutEvents(fn () => CalendarVersionRecord::query()->firstOrFail()->update(['name_original' => 'Changed'])))->toThrow(ImmutableVersion::class)
        ->and(fn () => CyclePositionRecord::withoutEvents(fn () => CyclePositionRecord::query()->firstOrFail()->delete()))->toThrow(ImmutableVersion::class)
        ->and(fn () => RevisionLogRecord::query()->firstOrFail()->delete())->toThrow(LogicException::class);
});

it('enforces uniqueness of calendars, versions and positions in the schema', function () {
    repo()->saveDraft(definition(cyclicCalendar('uniq')));
    $version = CalendarVersionRecord::query()->firstOrFail();
    $cycle = CycleRecord::query()->firstOrFail();

    expect(fn () => DB::table('nmc_calendars')->insert(['tenant_key' => '', 'identifier' => 'uniq']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('nmc_calendar_versions')->insert([...collect($version->getAttributes())->except(['id'])->all()]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('nmc_cycle_positions')->insert(['cycle_id' => $cycle->id, 'identifier' => 'p1', 'ordinal' => 99, 'label_original' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('nmc_cycle_positions')->insert(['cycle_id' => $cycle->id, 'identifier' => 'new', 'ordinal' => 1, 'label_original' => 'x']))->toThrow(QueryException::class);
});

it('allows the same identifier in different tenants', function () {
    repo()->saveDraft(definition(cyclicCalendar('shared'), 'a'));
    repo()->saveDraft(definition(cyclicCalendar('shared', length: 5), 'b'));

    expect(repo()->findVersion('a', 'shared', '1.0.0')?->cycles[0]->length())->toBe(8)
        ->and(repo()->findVersion('b', 'shared', '1.0.0')?->cycles[0]->length())->toBe(5)
        ->and(repo()->findVersion('', 'shared', '1.0.0'))->toBeNull();
});

it('rolls back a failed draft save completely', function () {
    // An anchor naming an unknown position fails inside the transaction, after rows were inserted.
    $bad = definition(cyclicCalendar('rollback', cycle: ['anchors' => [['id' => 'x', 'date' => '2026-01-01', 'position' => 'missing']]]));

    expect(fn () => repo()->saveDraft($bad))->toThrow(InvalidDefinition::class, 'unknown position')
        ->and(DB::table('nmc_calendars')->count())->toBe(0)
        ->and(DB::table('nmc_cycles')->count())->toBe(0)
        ->and(DB::table('nmc_cycle_positions')->count())->toBe(0);
});

it('refuses to load stored rows that no longer validate', function () {
    repo()->saveDraft(definition(cyclicCalendar('tampered')));
    DB::table('nmc_cycle_positions')->where('identifier', 'p2')->update(['identifier' => 'Not Valid']);

    expect(fn () => repo()->findVersion('', 'tampered', '1.0.0'))->toThrow(InvalidDefinition::class);
});

it('stores driver parameters and month names, and reloads them exactly', function () {
    $data = (require __DIR__.'/../../resources/definitions/reference.php')['calendars'][3];
    $data['names']['month'][0]['label']['translations'] = ['he' => 'ניסן'];
    $definition = definition([...$data, 'status' => 'draft', 'parameters' => []]);
    $julian = definition([...(require __DIR__.'/../../resources/definitions/reference.php')['calendars'][1], 'status' => 'draft']);

    repo()->saveDraft($definition);
    repo()->saveDraft($julian);

    expect(repo()->findVersion('', 'hebrew', '1.0.0')?->contentHash())->toBe($definition->contentHash())
        ->and(repo()->findVersion('', 'hebrew', '1.0.0')?->nameOf('month', 'nisan')?->label->label('he'))->toBe('ניסן')
        ->and(repo()->findVersion('', 'julian', '1.0.0')?->parameters)->toBe(['rule' => 'julian'])
        ->and(DB::table('nmc_calendar_names')->count())->toBe(26);
});
