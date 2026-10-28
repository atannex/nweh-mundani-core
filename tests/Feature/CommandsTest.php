<?php

declare(strict_types=1);

it('lists calendars', function () {
    $this->artisan('nweh-mundani:list')
        ->expectsOutputToContain('mundae-wabane')
        ->expectsOutputToContain('traditional-days (8)')
        ->assertSuccessful();
});

it('shows a calendar with its positions, anchor, source and assumption', function () {
    $this->artisan('nweh-mundani:show mmouck')
        ->expectsOutputToContain('Njœêngong')
        ->expectsOutputToContain('reference-2026-10-01')
        ->expectsOutputToContain('atannex-maintainer-2026-10')
        ->expectsOutputToContain('continuous-repetition')
        ->assertSuccessful();
});

it('converts a date', function () {
    $this->artisan('nweh-mundani:date nweh 2026-10-28')
        // The whole table is one write, so match one row in one expectation.
        ->expectsOutputToContain('| traditional-days | 4/8 (amina) | Amina | Amina    | 3   | assumed   | reference-2026-10-01 |')
        ->assertSuccessful();
});

it('prints JSON with the exact original spelling', function () {
    $this->artisan('nweh-mundani:date mundae-wabane 2026-10-01 --json')
        ->expectsOutputToContain('"original": "Aghà̧"')
        ->assertSuccessful();
});

it('fails with a clear message for unknown calendars and bad dates', function () {
    $this->artisan('nweh-mundani:date atlantis 2026-10-28')->expectsOutputToContain('not registered')->assertFailed();
    $this->artisan('nweh-mundani:date nweh 28/10/2026')->expectsOutputToContain('YYYY-MM-DD')->assertFailed();
});

it('compares calendars', function () {
    $this->artisan('nweh-mundani:compare 2026-10-04 mmouck mmock')
        ->expectsOutputToContain('| mmouck   | resolved |      | 4/8      | Njœêngong | assumed   |')
        ->assertSuccessful();
});

it('validates definitions and fails on errors', function () {
    $this->artisan('nweh-mundani:validate nweh')->expectsOutputToContain('valid')->assertSuccessful();
    $this->artisan('nweh-mundani:validate nweh --strict')->assertFailed();
    $this->artisan('nweh-mundani:validate atlantis')->assertFailed();
});

it('diagnoses the installation', function () {
    $this->artisan('nweh-mundani:diagnose')
        ->expectsOutputToContain('driver:cyclic')
        ->expectsOutputToContain('calendar:nweh')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

it('refuses to seed when persistence is off', function () {
    $this->artisan('nweh-mundani:seed-examples')->expectsOutputToContain('Persistence is disabled')->assertFailed();
});
