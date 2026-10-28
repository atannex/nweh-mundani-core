<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Facades\NwehMundani;

beforeEach(function () {
    config()->set('nweh-mundani.examples.reference', true);
});

it('registers reference calendars only when asked', function () {
    expect(array_keys(NwehMundani::calendars()))->toBe(['gregorian', 'hebrew', 'hijri-tabular', 'julian', 'mmock', 'mmouck', 'mundae-wabane', 'nweh']);
});

it('serves year/month/day calendars through the same read models', function () {
    $q = app(CalendarQueryService::class);
    $hebrew = $q->day('hebrew', '2026-10-28');

    expect($hebrew->components)->toMatchArray(['year' => 5787, 'month' => 8, 'day' => 17])
        ->and($hebrew->unit('month'))->toBe('Marheshvan')
        ->and($hebrew->toArray()['units'][0])->toMatchArray(['unit' => 'month', 'id' => 'marheshvan', 'original' => 'Marheshvan'])
        ->and($q->day('julian', '2026-10-28')->components)->toMatchArray(['year' => 2026, 'month' => 10, 'day' => 15])
        ->and($q->day('hijri-tabular', '2026-10-28')->unit('month'))->toBe('Jumada al-Ula')
        ->and($q->capabilities('hebrew'))->toBe(['from-civil', 'to-civil', 'periods', 'leap-rules', 'arithmetic', 'driver-validation'])
        ->and(NwehMundani::calendar('hebrew')->format(NwehMundani::calendar('hebrew')->fromCivil('2026-10-28'), '{component:day} {unit:month} {component:year}'))->toBe('17 Marheshvan 5787');
});

it('lists a month in the calendar’s own reckoning', function () {
    $tishri = app(CalendarQueryService::class)->nativeMonth('hebrew', 5787, 'tishri');

    expect($tishri->start)->toBe('2026-09-12')
        ->and($tishri->days)->toHaveCount(30)
        ->and($tishri->days[0]->components['day'])->toBe(1)
        ->and(NwehMundani::calendar('hebrew')->toCivil(['year' => 5787, 'month' => 'tishri', 'day' => 10])[0]->toString())->toBe('2026-09-21');
});

it('compares year/month/day calendars and traditional cycles side by side', function () {
    $comparison = app(CalendarQueryService::class)->compare('2026-10-28', ['nweh', 'gregorian', 'hebrew']);

    expect($comparison->get('nweh')?->label())->toBe('Amina')
        ->and($comparison->get('gregorian')?->unit('month'))->toBe('October')
        ->and($comparison->get('hebrew')?->unit('month'))->toBe('Marheshvan');
});

it('shows native dates in the commands', function () {
    $this->artisan('nweh-mundani:compare 2026-10-28 nweh hebrew julian')
        ->expectsOutputToContain('| hebrew   | resolved | 17 Marheshvan 5787 |')
        ->assertSuccessful();
    $this->artisan('nweh-mundani:date hijri-tabular 2026-10-28')->expectsOutputToContain('16 Jumada al-Ula 1448')->assertSuccessful();
});

it('gives year/month/day calendars a readable default label', function () {
    expect(NwehMundani::calendar('hebrew')->label('2026-10-28'))->toBe('17 Marheshvan 5787')
        ->and(NwehMundani::calendar('nweh')->label('2026-10-28'))->toBe('Amina');
});
