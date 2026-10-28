<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\CalendarCapabilities;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\ArithmeticCalendarDriver;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\HebrewDriver;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\SolarDriver;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\TabularLunarDriver;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;
use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Kernel\CalendarValidator;

function arithmetic(string $driver, array $parameters = [], array $extra = []): CalendarDefinition
{
    return definition(['id' => 'cal', 'name' => 'Cal', 'driver' => $driver, 'parameters' => $parameters, ...$extra]);
}

/** @return array{int, int, int} [year, month, day] as the driver numbers them */
function ymd(ArithmeticCalendarDriver $driver, CalendarDefinition $definition, CivilDate $date): array
{
    $c = $driver->fromCivil($definition, $date)->date()->components;

    return [$c['year'], $c['month'], $c['day']];
}

/** [year, 0-based month, day] from ICU for a civil day (read at noon UTC). */
function icuDate(string $calendar, CivilDate $date): array
{
    $cal = $calendar === 'julian' ? IntlGregorianCalendar::createInstance('UTC') : IntlCalendar::createInstance('UTC', "en_US@calendar={$calendar}");

    if ($cal instanceof IntlGregorianCalendar && $calendar === 'julian') {
        $cal->setGregorianChange(PHP_FLOAT_MAX); // pure (proleptic) Julian
    }

    $cal->setTime(((float) $date->dayNumber * 86400 + 43200) * 1000.0);
    $year = $cal->get(IntlCalendar::FIELD_YEAR);

    if ($calendar === 'julian' && $cal->get(IntlCalendar::FIELD_ERA) === 0) {
        $year = 1 - $year; // BC → astronomical
    }

    return [$year, $cal->get(IntlCalendar::FIELD_MONTH), $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH)];
}

dataset('icu calendars', [
    'julian' => ['julian', 'solar', ['rule' => 'julian']],
    'tabular hijri, civil epoch' => ['islamic-civil', 'lunar-tabular', ['epoch' => 'civil']],
    'tabular hijri, astronomical epoch' => ['islamic-tbla', 'lunar-tabular', ['epoch' => 'astronomical']],
]);

it('agrees with ICU on 3000 random days, both directions', function (string $icu, string $key, array $parameters) {
    $driver = app(DriverRegistry::class)->get($key);
    $definition = arithmetic($key, $parameters);
    mt_srand(11);

    for ($i = 0; $i < 3000; $i++) {
        $date = CivilDate::fromDayNumber(mt_rand(-400000, 200000)); // ≈ 875 … 2517 CE
        [$y, $m, $d] = ymd($driver, $definition, $date);

        expect([$y, $m - 1, $d])->toBe(icuDate($icu, $date))
            ->and($driver->toCivil($definition, ['year' => $y, 'month' => $m, 'day' => $d])[0]->toString())->toBe($date->toString());
    }
})->with('icu calendars')->skip(! class_exists(IntlCalendar::class), 'needs ext-intl');

it('agrees with PHP ext-calendar on 3000 random Hebrew days, and round-trips', function () {
    $driver = new HebrewDriver;
    $definition = arithmetic('lunisolar-hebrew');
    mt_srand(12);

    for ($i = 0; $i < 3000; $i++) {
        $date = CivilDate::fromDayNumber(mt_rand(-400000, 200000));
        [$y, $m, $d] = ymd($driver, $definition, $date);
        [$extMonth, $extDay, $extYear] = array_map('intval', explode('/', jdtojewish($date->dayNumber + 2440588)));

        // ext-calendar numbers Tishri = 1 … Shevat = 5, Adar I/Adar = 6, Adar II = 7, Nisan = 8 … Elul = 13.
        $expected = match (true) {
            $m >= 7 && $m <= 11 => $m - 6,
            $m === 12 => [6, 7],
            $m === 13 => 7,
            default => $m + 7,
        };

        expect([$y, $d])->toBe([$extYear, $extDay])
            ->and(in_array($extMonth, (array) $expected, true))->toBeTrue()
            ->and($driver->toCivil($definition, ['year' => $y, 'month' => $m, 'day' => $d])[0]->toString())->toBe($date->toString());
    }
})->skip(! function_exists('jdtojewish'), 'needs ext-calendar');

it('keeps every Hebrew year within the calendar’s invariants for 6000 years', function () {
    $driver = new HebrewDriver;
    $definition = arithmetic('lunisolar-hebrew');

    for ($year = 1; $year <= 6000; $year++) {
        $start = $driver->toCivil($definition, ['year' => $year, 'month' => 'tishri', 'day' => 1])[0];
        $length = $start->daysUntil($driver->toCivil($definition, ['year' => $year + 1, 'month' => 'tishri', 'day' => 1])[0]);

        expect(in_array($length, [353, 354, 355, 383, 384, 385], true))->toBeTrue()
            ->and($length > 380)->toBe($driver->isLeap($definition, 'year', $year))
            // lo ADU rosh: 1 Tishri is never a Sunday, Wednesday or Friday.
            ->and(in_array($start->isoWeekday(), [7, 3, 5], true))->toBeFalse();
    }
});

it('matches independently known dates', function () {
    $hebrew = arithmetic('lunisolar-hebrew');
    $h = new HebrewDriver;

    expect((string) $h->toCivil($hebrew, ['year' => 5785, 'month' => 'tishri', 'day' => 1])[0])->toBe('2024-10-03')  // Rosh Hashanah 5785
        ->and((string) $h->toCivil($hebrew, ['year' => 5784, 'month' => 'nisan', 'day' => 15])[0])->toBe('2024-04-23') // Pesach 5784
        // The Hebrew epoch is defined as Julian 7 October 3761 BCE (−3760): computed here by the independent Julian formulas.
        ->and((string) (new SolarDriver)->toCivil(arithmetic('solar', ['rule' => 'julian']), ['year' => -3760, 'month' => 10, 'day' => 7])[0])->toBe(CivilDate::fromDayNumber(HebrewDriver::EPOCH - 719163)->toString())
        ->and(ymd(new SolarDriver, arithmetic('solar', ['rule' => 'julian']), CivilDate::parse('2026-10-28')))->toBe([2026, 10, 15]) // 13 days behind since 1900
        ->and((string) (new TabularLunarDriver)->toCivil(arithmetic('lunar-tabular'), ['year' => 1, 'month' => 1, 'day' => 1])[0])->toBe('0622-07-19')   // Julian 16 July 622
        ->and((string) (new TabularLunarDriver)->toCivil(arithmetic('lunar-tabular', ['epoch' => 'astronomical']), ['year' => 1, 'month' => 1, 'day' => 1])[0])->toBe('0622-07-18');
});

it('matches CivilDate exactly for the Gregorian rule', function () {
    mt_srand(13);
    $driver = new SolarDriver;
    $definition = arithmetic('solar');

    for ($i = 0; $i < 500; $i++) {
        $date = CivilDate::fromDayNumber(mt_rand(-3000000, 2900000));
        expect(ymd($driver, $definition, $date))->toBe([$date->year, $date->month, $date->day]);
    }
});

it('reports leap years, month lengths and identifiers', function () {
    $h = new HebrewDriver;
    $hebrew = arithmetic('lunisolar-hebrew');
    $leapAdar = $h->fromCivil($hebrew, $h->toCivil($hebrew, ['year' => 5784, 'month' => 12, 'day' => 1])[0])->date();

    expect($h->isLeap($hebrew, 'year', 5784))->toBeTrue()
        ->and($h->isLeap($hebrew, 'year', 5785))->toBeFalse()
        ->and($leapAdar->identifiers['month'])->toBe('adar-i')
        ->and($leapAdar->component('months_in_year'))->toBe(13)
        ->and((new TabularLunarDriver)->isLeap(arithmetic('lunar-tabular'), 'year', 2))->toBeTrue()
        ->and((new TabularLunarDriver)->isLeap(arithmetic('lunar-tabular'), 'year', 3))->toBeFalse()
        ->and((new SolarDriver)->isLeap(arithmetic('solar', ['rule' => 'julian']), 'year', 1900))->toBeTrue()
        ->and((new SolarDriver)->isLeap(arithmetic('solar'), 'year', 1900))->toBeFalse();
});

it('refuses days that do not exist and dates before an epoch', function () {
    expect(fn () => (new HebrewDriver)->toCivil(arithmetic('lunisolar-hebrew'), ['year' => 5785, 'month' => 'adar-ii', 'day' => 1]))->toThrow(DateOutOfRange::class)
        ->and(fn () => (new TabularLunarDriver)->toCivil(arithmetic('lunar-tabular'), ['year' => 1446, 'month' => 2, 'day' => 30]))->toThrow(DateOutOfRange::class)
        ->and(fn () => (new SolarDriver)->toCivil(arithmetic('solar', ['rule' => 'julian']), ['year' => 1900, 'month' => 2, 'day' => 30]))->toThrow(DateOutOfRange::class)
        ->and((new TabularLunarDriver)->fromCivil(arithmetic('lunar-tabular'), CivilDate::parse('0600-01-01'))->status)->toBe(ConversionStatus::OutOfRange)
        ->and((new HebrewDriver)->fromCivil(arithmetic('lunisolar-hebrew'), CivilDate::parse('-3761-01-01'))->status)->toBe(ConversionStatus::OutOfRange);
});

it('adds days, months and years in the calendar’s own terms', function () {
    $h = new HebrewDriver;
    $hebrew = arithmetic('lunisolar-hebrew');
    $shevat = $h->fromCivil($hebrew, $h->toCivil($hebrew, ['year' => 5784, 'month' => 'shevat', 'day' => 30])[0])->date();
    $adar = $h->fromCivil($hebrew, $h->toCivil($hebrew, ['year' => 5785, 'month' => 'adar', 'day' => 14])[0])->date();
    $muharram = (new TabularLunarDriver)->fromCivil(arithmetic('lunar-tabular'), (new TabularLunarDriver)->toCivil(arithmetic('lunar-tabular'), ['year' => 1446, 'month' => 1, 'day' => 30])[0])->date();

    expect($h->add($hebrew, $shevat, 1, 'month')->identifiers['month'])->toBe('adar-i')
        ->and($h->add($hebrew, $shevat, 2, 'month')->identifiers['month'])->toBe('adar-ii')
        ->and($h->add($hebrew, $shevat, 2, 'month')->component('day'))->toBe(29)          // clamped: Adar II has 29 days
        ->and($h->add($hebrew, $adar, -1, 'year')->identifiers['month'])->toBe('adar-ii') // Adar (common) → Adar II (leap)
        ->and($h->add($hebrew, $shevat, 12, 'month')->component('year'))->toBe(5785)
        ->and((new TabularLunarDriver)->add(arithmetic('lunar-tabular'), $muharram, 1, 'month')->components)->toMatchArray(['month' => 2, 'day' => 29])
        ->and((string) $h->add($hebrew, $adar, 10, 'day')->civil)->toBe((string) $adar->civil->addDays(10));
});

it('enumerates months and years as civil ranges', function () {
    $h = new HebrewDriver;
    $hebrew = arithmetic('lunisolar-hebrew');
    $year = $h->periodContaining($hebrew, 'year', CivilDate::parse('2025-01-01'));

    expect($year->sequence)->toBe(5785)
        ->and((string) $year->range)->toBe('2024-10-03..2025-09-22')
        ->and(count($year->range))->toBe(355)
        ->and(count($h->periodsBetween($hebrew, 'month', CalendarDateRange::between('2024-10-03', '2025-09-22'))))->toBe(12);
});

it('runs cycles alongside year/month/day reckoning (composite calendars)', function () {
    $composite = arithmetic('solar', extra: ['cycles' => [[
        'id' => 'market', 'positions' => array_map(fn ($i) => ['id' => "d{$i}", 'label' => "D{$i}"], range(1, 4)),
        'anchors' => [['id' => 'a', 'date' => '2026-10-01', 'position' => 'd1']],
    ]]]);
    $date = (new SolarDriver)->fromCivil($composite, CivilDate::parse('2026-10-06'))->date();

    expect($date->component('day'))->toBe(6)
        ->and($date->cycle('market')->ordinal())->toBe(2)
        ->and((string) (new SolarDriver)->nextOccurrence($composite, 'market', 'd1', CivilDate::parse('2026-10-06')))->toBe('2026-10-09')
        ->and(CalendarCapabilities::ofDriver(new SolarDriver, $composite)->toArray())->toContain('cycle-queries')
        ->and(CalendarCapabilities::ofDriver(new SolarDriver, arithmetic('solar'))->toArray())->not->toContain('cycle-queries');
});

it('validates driver parameters and month names', function () {
    $validator = app(CalendarValidator::class);

    expect($validator->validate(arithmetic('solar', ['rule' => 'french-republican']))->passes())->toBeFalse()
        ->and($validator->validate(arithmetic('solar', ['leap' => 'yes']))->passes())->toBeFalse()
        ->and($validator->validate(arithmetic('lunisolar-hebrew', extra: ['names' => ['month' => [['id' => 'm13', 'label' => 'X']]]]))->passes())->toBeFalse()
        ->and($validator->validate(arithmetic('lunisolar-hebrew', extra: ['names' => ['month' => [['id' => 'adar-ii', 'label' => 'Adar II']]]]))->passes())->toBeTrue();
});
