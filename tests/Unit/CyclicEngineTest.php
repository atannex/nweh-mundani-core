<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\Certainty;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Cyclic\CyclicDriver;
use Atannex\NwehMundani\Core\Exceptions\ConflictingAnchors;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\CalendarValidator;

function ordinalOn(CalendarDefinition $definition, string $date, string $cycle = 'days'): ?int
{
    return (new CyclicDriver)->fromCivil($definition, CivilDate::parse($date))->date?->cycles[$cycle]?->ordinal();
}

it('supports arbitrary positive cycle lengths', function (int $length) {
    $definition = definition(cyclicCalendar(length: $length));

    expect(ordinalOn($definition, '2026-10-01'))->toBe(1)
        ->and(ordinalOn($definition, CivilDate::parse('2026-10-01')->addDays($length - 1)->toString()))->toBe($length)
        ->and(ordinalOn($definition, CivilDate::parse('2026-10-01')->addDays($length)->toString()))->toBe(1)
        ->and(ordinalOn($definition, '2026-09-30'))->toBe($length);
})->with([1, 2, 5, 6, 7, 8, 10, 13, 60, 365]);

it('matches the modulo formula for any date and anchor, before and after the anchor (property)', function () {
    mt_srand(8);

    for ($i = 0; $i < 300; $i++) {
        $length = mt_rand(1, 40);
        $anchorPosition = mt_rand(1, $length);
        $anchor = CivilDate::fromDayNumber(mt_rand(-50000, 50000));
        $definition = definition(cyclicCalendar(length: $length, anchorDate: $anchor->toString(), anchorPosition: $anchorPosition));

        for ($j = 0; $j < 20; $j++) {
            $offset = mt_rand(-100000, 100000);
            $expected = ((($offset + $anchorPosition - 1) % $length) + $length) % $length + 1;

            expect(ordinalOn($definition, $anchor->addDays($offset)->toString()))->toBe($expected);
        }
    }
});

it('steps exactly one position per civil day across month, year, leap-day and DST boundaries', function () {
    $definition = definition(cyclicCalendar(length: 7, anchorDate: '2024-02-27'));

    $sequence = array_map(fn (string $d): ?int => ordinalOn($definition, $d), ['2024-02-27', '2024-02-28', '2024-02-29', '2024-03-01', '2024-03-31', '2024-04-01']);
    expect($sequence)->toBe([1, 2, 3, 4, 6, 7]); // 2024-03-31 is 33 days on: 33 mod 7 = 5

    $year = definition(cyclicCalendar(length: 8, anchorDate: '2026-12-30'));
    expect([ordinalOn($year, '2026-12-31'), ordinalOn($year, '2027-01-01'), ordinalOn($year, '2027-01-02')])->toBe([2, 3, 4]);
});

it('converts instants through the context timezone, not elapsed seconds', function () {
    $calendar = app(CalendarManager::class)->withTimezone('Europe/London');
    $calendar->register(cyclicCalendar('dst', 8, '2026-03-28'));

    // 2026-03-29 has only 23 hours in London; midnight-to-midnight is still one day.
    $before = new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London'));
    $after = new DateTimeImmutable('2026-03-30 00:30', new DateTimeZone('Europe/London'));

    expect($calendar->calendar('dst')->fromCivil($before)->primary()?->ordinal())->toBe(2)
        ->and($calendar->calendar('dst')->fromCivil($after)->primary()?->ordinal())->toBe(3);
});

it('runs several independent cycles in one calendar', function () {
    $definition = definition([
        'id' => 'multi',
        'name' => 'Multi',
        'cycles' => [
            ['id' => 'five', 'positions' => array_map(fn ($i) => ['id' => "f{$i}", 'label' => "F{$i}"], range(1, 5)), 'anchors' => [['id' => 'a', 'date' => '2026-01-01', 'position' => 'f1']]],
            ['id' => 'seven', 'positions' => array_map(fn ($i) => ['id' => "s{$i}", 'label' => "S{$i}"], range(1, 7)), 'anchors' => [['id' => 'a', 'date' => '2026-01-03', 'position' => 's4']]],
        ],
    ]);

    $date = (new CyclicDriver)->fromCivil($definition, CivilDate::parse('2026-01-10'))->date();

    expect($date->cycle('five')->ordinal())->toBe(5)   // 9 days after f1 → index 9 mod 5 = 4
        ->and($date->cycle('seven')->ordinal())->toBe(4) // 7 days after s4 → s4 again
        ->and($date->label())->toBe('F5');
});

it('labels results by certainty: anchor dates are recorded, others assumed or derived', function () {
    $assumed = definition(cyclicCalendar());
    $verified = definition(cyclicCalendar(cycle: ['continuity' => 'verified']));
    $driver = new CyclicDriver;

    expect($driver->fromCivil($assumed, CivilDate::parse('2026-10-01'))->date()->cycle('days')->certainty)->toBe(Certainty::Recorded)
        ->and($driver->fromCivil($assumed, CivilDate::parse('2026-10-02'))->date()->cycle('days')->certainty)->toBe(Certainty::Assumed)
        ->and($driver->fromCivil($verified, CivilDate::parse('2026-10-02'))->date()->cycle('days')->certainty)->toBe(Certainty::Derived);
});

it('numbers cycle runs from the anchor, or from the epoch when one is set', function () {
    $driver = new CyclicDriver;
    $plain = definition(cyclicCalendar());
    $epoch = definition(cyclicCalendar(overrides: ['epoch' => ['date' => '2026-09-01']]));

    expect($driver->fromCivil($plain, CivilDate::parse('2026-10-08'))->date()->cycle('days')->sequence)->toBe(0)
        ->and($driver->fromCivil($plain, CivilDate::parse('2026-10-09'))->date()->cycle('days')->sequence)->toBe(1)
        ->and($driver->fromCivil($plain, CivilDate::parse('2026-09-30'))->date()->cycle('days')->sequence)->toBe(-1)
        // 2026-09-01 lies in run -4 relative to the anchor (30 days earlier → floor(-30/8) = -4).
        ->and($driver->fromCivil($epoch, CivilDate::parse('2026-10-01'))->date()->cycle('days')->sequence)->toBe(4);
});

it('honours anchor effective ranges and reports dates no anchor covers', function () {
    $definition = definition(cyclicCalendar(cycle: ['anchors' => [
        ['id' => 'old', 'date' => '2026-01-01', 'position' => 'p1', 'effective' => ['from' => null, 'until' => '2026-06-30']],
        ['id' => 'new', 'date' => '2026-08-01', 'position' => 'p3', 'effective' => ['from' => '2026-08-01', 'until' => null]],
    ]]));
    $driver = new CyclicDriver;

    expect($driver->fromCivil($definition, CivilDate::parse('2026-06-30'))->date()->cycle('days')->anchorId)->toBe('old')
        ->and($driver->fromCivil($definition, CivilDate::parse('2026-07-15'))->status)->toBe(ConversionStatus::OutOfRange)
        ->and($driver->fromCivil($definition, CivilDate::parse('2026-08-01'))->date()->cycle('days')->ordinal())->toBe(3);
});

it('accepts overlapping anchors that agree and uses the earliest', function () {
    $definition = definition(cyclicCalendar(cycle: ['anchors' => [
        ['id' => 'b', 'date' => '2026-10-09', 'position' => 'p1'],
        ['id' => 'a', 'date' => '2026-10-01', 'position' => 'p1'],
    ]]));

    expect(app(CalendarValidator::class)->validate($definition)->passes())->toBeTrue()
        ->and((new CyclicDriver)->fromCivil($definition, CivilDate::parse('2027-01-01'))->date()->cycle('days')->anchorId)->toBe('a');
});

it('refuses to choose between contradictory anchors', function () {
    $definition = definition(cyclicCalendar(cycle: ['anchors' => [
        ['id' => 'a', 'date' => '2026-10-01', 'position' => 'p1'],
        ['id' => 'b', 'date' => '2026-10-02', 'position' => 'p1'],
    ]]));

    $report = app(CalendarValidator::class)->validate($definition);

    expect($report->passes())->toBeFalse()
        ->and((string) $report->errors[0])->toContain('disagree')
        ->and(fn () => (new CyclicDriver)->fromCivil($definition, CivilDate::parse('2026-10-05')))->toThrow(ConflictingAnchors::class);
});

it('applies explicit date overrides to that date only', function () {
    $definition = definition(cyclicCalendar(cycle: ['exceptions' => [['id' => 'fix', 'date' => '2026-10-05', 'position' => 'p8', 'reason' => 'Recorded correction']]]));
    $driver = new CyclicDriver;
    $reading = $driver->fromCivil($definition, CivilDate::parse('2026-10-05'))->date()->cycle('days');

    expect($reading->ordinal())->toBe(8)
        ->and($reading->exceptionId)->toBe('fix')
        ->and($reading->certainty)->toBe(Certainty::Recorded)
        ->and(ordinalOn($definition, '2026-10-06'))->toBe(6);
});

it('respects calendar and cycle effective ranges', function () {
    $definition = definition(cyclicCalendar(overrides: ['effective' => ['from' => '2020-01-01', 'until' => '2030-12-31']]));
    $driver = new CyclicDriver;

    expect($driver->fromCivil($definition, CivilDate::parse('2020-01-01'))->isResolved())->toBeTrue()
        ->and($driver->fromCivil($definition, CivilDate::parse('2019-12-31'))->status)->toBe(ConversionStatus::OutOfRange)
        ->and($driver->fromCivil($definition, CivilDate::parse('2031-01-01'))->status)->toBe(ConversionStatus::OutOfRange);
});

it('finds next and previous occurrences exactly like a day-by-day scan (property)', function () {
    mt_srand(42);
    $driver = new CyclicDriver;

    for ($i = 0; $i < 40; $i++) {
        $length = mt_rand(2, 12);
        $definition = definition(cyclicCalendar(length: $length, cycle: [
            'anchors' => [
                ['id' => 'a', 'date' => '2026-01-01', 'position' => 'p1', 'effective' => ['from' => null, 'until' => '2026-03-31']],
                ['id' => 'b', 'date' => '2026-05-01', 'position' => 'p'.mt_rand(1, $length), 'effective' => ['from' => '2026-05-01', 'until' => null]],
            ],
            'exceptions' => [
                ['id' => 'x1', 'date' => '2026-02-1'.mt_rand(0, 9), 'position' => 'p'.mt_rand(1, $length)],
                ['id' => 'x2', 'date' => '2026-04-15', 'position' => 'p'.mt_rand(1, $length)],
            ],
        ]));

        $from = CivilDate::parse('2026-01-01')->addDays(mt_rand(-20, 160));
        $target = 'p'.mt_rand(1, $length);

        $scan = static function (int $step) use ($driver, $definition, $from, $target): ?string {
            for ($d = 0; $d <= 400; $d++) {
                $day = $from->addDays($d * $step);
                if (($driver->fromCivil($definition, $day)->date?->cycles['days'] ?? null)?->positionId() === $target) {
                    return $day->toString();
                }
            }

            return null;
        };

        expect($driver->nextOccurrence($definition, 'days', $target, $from)?->toString())->toBe($scan(1))
            ->and($driver->previousOccurrence($definition, 'days', $target, $from)?->toString())->toBe($scan(-1));
    }
});

it('lists occurrences and periods in a range', function () {
    $definition = definition(cyclicCalendar());
    $driver = new CyclicDriver;
    $range = CalendarDateRange::between('2026-10-01', '2026-10-31');

    expect(array_map('strval', $driver->occurrencesBetween($definition, 'days', 'p1', $range)))->toBe(['2026-10-01', '2026-10-09', '2026-10-17', '2026-10-25'])
        ->and($driver->periodContaining($definition, 'days', CivilDate::parse('2026-10-12'))->toArray())->toMatchArray(['start' => '2026-10-09', 'end' => '2026-10-16', 'sequence' => 1])
        ->and(count($driver->periodsBetween($definition, 'days', $range)))->toBe(4); // runs starting 1, 9, 17 and 25 October
});
