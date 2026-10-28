<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CivilDate;

it('parses strict ISO dates and rejects anything else', function (string $input) {
    expect(fn () => CivilDate::parse($input))->toThrow(InvalidArgumentException::class);
})->with(['2026-2-01', '2026-02-30', '2025-02-29', '2026-13-01', '26-01-01', '2026-10-01T00:00:00', '', 'tomorrow']);

it('round-trips day numbers against PHP DateTime over 3000 random dates (property)', function () {
    mt_srand(20261028);

    for ($i = 0; $i < 3000; $i++) {
        $days = mt_rand(-200000, 200000);
        $civil = CivilDate::fromDayNumber($days);
        $reference = (new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' days');

        expect($civil->toString())->toBe($reference->format('Y-m-d'))
            ->and(CivilDate::parse($civil->toString())->dayNumber)->toBe($days)
            ->and($civil->isoWeekday())->toBe((int) $reference->format('N'));
    }
});

it('counts signed whole days across leap years and boundaries', function () {
    expect(CivilDate::parse('2024-02-28')->daysUntil(CivilDate::parse('2024-03-01')))->toBe(2)
        ->and(CivilDate::parse('2023-02-28')->daysUntil(CivilDate::parse('2023-03-01')))->toBe(1)
        ->and(CivilDate::parse('2026-12-31')->addDays(1)->toString())->toBe('2027-01-01')
        ->and(CivilDate::parse('2026-10-01')->daysUntil(CivilDate::parse('2026-09-30')))->toBe(-1)
        ->and(CivilDate::parse('1900-02-28')->addDays(1)->toString())->toBe('1900-03-01')
        ->and(CivilDate::parse('2000-02-28')->addDays(1)->toString())->toBe('2000-02-29');
});

it('reads an instant in an explicit timezone', function () {
    $instant = new DateTimeImmutable('2026-10-01 23:30:00', new DateTimeZone('UTC'));

    expect(CivilDate::fromInstant($instant, 'Africa/Douala')->toString())->toBe('2026-10-02')
        ->and(CivilDate::fromInstant($instant, 'America/New_York')->toString())->toBe('2026-10-01')
        ->and(CivilDate::fromInstant($instant)->toString())->toBe('2026-10-01');
});

it('is unaffected by daylight-saving transitions', function () {
    // Europe/London springs forward on 2026-03-29 (a 23-hour day).
    $before = CivilDate::fromInstant(new DateTimeImmutable('2026-03-28 12:00', new DateTimeZone('Europe/London')));
    $after = CivilDate::fromInstant(new DateTimeImmutable('2026-03-30 12:00', new DateTimeZone('Europe/London')));

    expect($before->daysUntil($after))->toBe(2);
});

it('uses astronomical year numbering before the common era', function () {
    // Year 0 is 1 BCE and a proleptic leap year; year -1 (2 BCE) is not.
    expect(CivilDate::parse('0000-02-29')->addDays(1)->toString())->toBe('0000-03-01')
        ->and(CivilDate::parse('-0004-02-29')->isLeapYear())->toBeTrue()
        ->and(fn () => CivilDate::parse('-0001-02-29'))->toThrow(InvalidArgumentException::class)
        ->and(CivilDate::parse('0001-01-01')->addDays(-1)->toString())->toBe('0000-12-31');
});

it('iterates inclusive ranges', function () {
    $range = CalendarDateRange::between('2026-12-30', '2027-01-02');

    expect(count($range))->toBe(4)
        ->and(array_map('strval', iterator_to_array($range)))->toBe(['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'])
        ->and(fn () => CalendarDateRange::between('2026-01-02', '2026-01-01'))->toThrow(InvalidArgumentException::class);
});
