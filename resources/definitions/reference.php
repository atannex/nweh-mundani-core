<?php

declare(strict_types=1);

/*
 * Optional reference definitions for the arithmetic drivers, registered only
 * when config('nweh-mundani.examples.reference') is true.
 *
 * Month names are the conventional English names and transliterations
 * (language "en"); add native-script names or other transliterations as
 * translations or alternates in your own definitions. The tabular Hijri
 * calendar is the arithmetic one: it does not follow crescent sighting or
 * national announcements and can differ from them by a day or two.
 */

$months = static fn (array $names, string $prefix = 'm'): array => array_map(
    static fn (int|string $id, string $name): array => ['id' => is_int($id) ? $prefix.($id + 1) : $id, 'label' => ['original' => $name, 'language' => 'en']],
    array_keys($names),
    $names,
);

$solar = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

return [
    'calendars' => [
        [
            'id' => 'gregorian',
            'name' => ['original' => 'Gregorian', 'language' => 'en'],
            'driver' => 'solar',
            'parameters' => ['rule' => 'gregorian'],
            'names' => ['month' => $months($solar)],
            'metadata' => ['verification' => 'verified', 'tags' => ['reference', 'solar']],
        ],
        [
            'id' => 'julian',
            'name' => ['original' => 'Julian', 'language' => 'en'],
            'driver' => 'solar',
            'parameters' => ['rule' => 'julian'],
            'names' => ['month' => $months($solar)],
            'metadata' => ['verification' => 'verified', 'tags' => ['reference', 'solar']],
        ],
        [
            'id' => 'hijri-tabular',
            'name' => ['original' => 'Hijri (tabular)', 'language' => 'en'],
            'driver' => 'lunar-tabular',
            'parameters' => ['epoch' => 'civil'],
            'names' => ['month' => $months(['Muharram', 'Safar', 'Rabi al-Awwal', 'Rabi al-Thani', 'Jumada al-Ula', 'Jumada al-Akhirah', 'Rajab', 'Shaban', 'Ramadan', 'Shawwal', 'Dhu al-Qadah', 'Dhu al-Hijjah'])],
            'metadata' => ['verification' => 'verified', 'tags' => ['reference', 'lunar'], 'assumptions' => [[
                'id' => 'arithmetic-not-observational',
                'statement' => 'Months follow the 30-year tabular cycle, not crescent sighting; locally announced dates may differ by a day or two.',
                'status' => 'verified',
            ]]],
        ],
        [
            'id' => 'hebrew',
            'name' => ['original' => 'Hebrew', 'language' => 'en'],
            'driver' => 'lunisolar-hebrew',
            'names' => ['month' => $months([
                'nisan' => 'Nisan', 'iyyar' => 'Iyyar', 'sivan' => 'Sivan', 'tammuz' => 'Tammuz', 'av' => 'Av', 'elul' => 'Elul',
                'tishri' => 'Tishri', 'marheshvan' => 'Marheshvan', 'kislev' => 'Kislev', 'tevet' => 'Tevet', 'shevat' => 'Shevat',
                'adar' => 'Adar', 'adar-i' => 'Adar I', 'adar-ii' => 'Adar II',
            ])],
            'metadata' => ['verification' => 'verified', 'tags' => ['reference', 'lunisolar']],
        ],
    ],
];
