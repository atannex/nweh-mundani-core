<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Dates\Certainty;
use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\CalendarRegistry;
use Atannex\NwehMundani\Core\Kernel\CalendarResolver;

dataset('calendars', ['nweh', 'mundae-wabane', 'mmouck', 'mmock']);

function dayOn(string $calendar, string $date): string
{
    return NwehMundani::calendar($calendar)->fromCivil($date)->cycle('traditional-days')->label();
}

it('resolves 1 October 2026 to the anchor day of each calendar', function () {
    expect(dayOn('nweh', '2026-10-01'))->toBe('Ankoah')
        ->and(dayOn('mundae-wabane', '2026-10-01'))->toBe('Aghà̧')
        ->and(dayOn('mmouck', '2026-10-01'))->toBe('Ngangà')
        ->and(dayOn('mmock', '2026-10-01'))->toBe('Ngangà');
});

it('resolves 2 October 2026 to the second day of each calendar', function () {
    expect(dayOn('nweh', '2026-10-02'))->toBe('Anzoah')
        ->and(dayOn('mundae-wabane', '2026-10-02'))->toBe('Abùꞌ')
        ->and(dayOn('mmouck', '2026-10-02'))->toBe('Mbeqgnúá')
        ->and(dayOn('mmock', '2026-10-02'))->toBe('Betaâgnúá');
});

it('keeps Mmouck and Mmock distinct on 4 October 2026', function () {
    expect(dayOn('mmouck', '2026-10-04'))->toBe('Njœêngong')
        ->and(dayOn('mmock', '2026-10-04'))->toBe('Ngong');
});

it('returns to position one on 9 October 2026', function (string $calendar) {
    $reading = NwehMundani::calendar($calendar)->fromCivil('2026-10-09')->cycle('traditional-days');

    expect($reading->ordinal())->toBe(1)
        ->and($reading->length)->toBe(8)
        ->and($reading->sequence)->toBe(1);
})->with('calendars');

it('lists all eight days in the supplied order', function () {
    $labels = fn (string $id) => array_map(fn (string $d) => dayOn($id, $d), ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

    expect($labels('nweh'))->toBe(['Ankoah', 'Anzoah', 'Alena', 'Amina', 'Afeah', 'Agong', 'Aseih', 'Alung'])
        ->and($labels('mundae-wabane'))->toBe(['Aghà̧', 'Abùꞌ', 'Ngȩ̀', 'Kèlu̧', 'Èwenesa̧', 'Èwenelà̧', 'Nkwanyȩ', 'Mèndeè'])
        ->and($labels('mmouck'))->toBe(['Ngangà', 'Mbeqgnúá', 'Mbeqlěq', 'Njœêngong', 'Mbeqńkœó', 'Njœêlekœr̄', 'Faꞌà', 'Télǎng'])
        ->and($labels('mmock'))->toBe(['Ngangà', 'Betaâgnúá', 'Mbeqlěq', 'Ngong', 'Mbeqńkœó', 'Njœêlekœr̄', 'Faꞌà', 'Télǎng']);
});

it('stores the supplied spellings code point for code point', function () {
    $codepoints = fn (string $s): string => implode(' ', array_map(fn ($c) => sprintf('%04X', mb_ord($c)), mb_str_split($s)));

    // Written with escapes so this test does not depend on how an editor composes them.
    expect($codepoints(dayOn('mundae-wabane', '2026-10-01')))->toBe($codepoints("Agh\u{00E0}\u{0327}"))
        ->and($codepoints(dayOn('mundae-wabane', '2026-10-02')))->toBe($codepoints("Ab\u{00F9}\u{A78C}"))
        ->and($codepoints(dayOn('mundae-wabane', '2026-10-03')))->toBe($codepoints("Ng\u{0229}\u{0300}"))
        ->and($codepoints(dayOn('mundae-wabane', '2026-10-04')))->toBe($codepoints("K\u{00E8}lu\u{0327}"))
        ->and($codepoints(dayOn('mmouck', '2026-10-06')))->toBe($codepoints("Nj\u{0153}\u{00EA}lek\u{0153}r\u{0304}"))
        ->and($codepoints(dayOn('mmock', '2026-10-07')))->toBe($codepoints("Fa\u{A78C}\u{00E0}"));
});

it('resolves dates before the anchor', function () {
    // 30 September 2026 is one day before the anchor: the eighth day.
    expect(dayOn('nweh', '2026-09-30'))->toBe('Alung')
        ->and(dayOn('mmock', '2026-09-30'))->toBe('Télǎng')
        // 2025-10-01 is 365 days earlier: −365 mod 8 = 3 → fourth day.
        ->and(dayOn('nweh', '2025-10-01'))->toBe('Amina');
});

it('resolves 28 October 2026', function () {
    // 27 days after the anchor: 27 mod 8 = 3 → fourth day.
    expect(NwehMundani::compare('2026-10-28', ['nweh', 'mundae-wabane', 'mmouck', 'mmock'])->labels())
        ->toBe(['nweh' => 'Amina', 'mundae-wabane' => 'Kèlu̧', 'mmouck' => 'Njœêngong', 'mmock' => 'Ngong']);
});

it('marks extrapolated days as resting on the continuous-repetition assumption', function () {
    $calendar = NwehMundani::calendar('nweh');

    expect($calendar->fromCivil('2026-10-01')->cycle('traditional-days')->certainty)->toBe(Certainty::Recorded)
        ->and($calendar->fromCivil('2026-10-28')->cycle('traditional-days')->certainty)->toBe(Certainty::Assumed)
        ->and($calendar->definition()->metadata->assumption('continuous-repetition')?->status)->toBe(VerificationStatus::Submitted)
        ->and($calendar->definition()->metadata->verification)->not->toBe(VerificationStatus::Verified);
});

it('records no observances, market days or meanings for the reference calendars', function (string $calendar) {
    expect(NwehMundani::calendar($calendar)->observances())->toBe([]);
})->with('calendars');

it('validates every reference calendar, with the assumption surfaced as a warning', function (string $calendar) {
    $report = NwehMundani::calendar($calendar)->validate();

    expect($report->passes())->toBeTrue()
        ->and(implode("\n", array_map('strval', $report->warnings)))->toContain('continuous-repetition');
})->with('calendars');

it('can be switched off', function () {
    config()->set('nweh-mundani.examples.register', false);
    app()->forgetInstance(CalendarRegistry::class);
    app()->forgetInstance(CalendarResolver::class);
    app()->forgetInstance(CalendarManager::class);
    NwehMundani::clearResolvedInstances();

    expect(NwehMundani::calendars())->toBe([]);
});
