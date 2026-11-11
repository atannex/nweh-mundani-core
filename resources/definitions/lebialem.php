<?php

declare(strict_types=1);

/*
 * Initial Lebialem reference datasets: four independent eight-day cycles.
 *
 * This file is data, not logic. The spellings are reproduced exactly as
 * supplied (Unicode combining marks and saltillos included; do not
 * normalise). Position identifiers are ASCII technical keys derived from
 * the spellings for storage and URLs only; they are not spellings.
 *
 * What is recorded: the order of the eight days in each calendar and that
 * 2026-10-01 is the first listed day in all four. What is assumed: that the
 * days repeat without interruption on either side of that date. No market
 * days, ceremonial or religious meanings are recorded, because none have
 * been supplied with sources.
 *
 * M'Mouck and M'Mock are separate calendars and are deliberately not merged.
 *
 * Earlier spellings of the calendar names (Mundae (Wabane), Mmouck, Mmock)
 * are kept as alternates so searches for them still match.
 */

$source = 'atannex-maintainer-2026-10';

$calendar = static function (string $id, string $name, array $positions, array $alternates = []) use ($source): array {
    return [
        'id' => $id,
        'version' => '1.0.0',
        'status' => 'published',
        'name' => ['original' => $name, 'alternates' => $alternates],
        'driver' => 'cyclic',
        'metadata' => [
            'region' => 'Lebialem',
            'verification' => 'submitted',
            'sources' => [$source],
            'tags' => ['lebialem', 'eight-day-cycle'],
            'assumptions' => [[
                'id' => 'continuous-repetition',
                'statement' => 'The eight days repeat without interruption before and after the 2026-10-01 anchor; not yet checked against further dated records.',
                'status' => 'submitted',
            ]],
        ],
        'cycles' => [[
            'id' => 'traditional-days',
            'name' => ['original' => 'Traditional days', 'language' => 'en'],
            'continuity' => 'assumed',
            'positions' => array_map(
                static fn (array $p): array => ['id' => $p[0], 'label' => ['original' => $p[1]]],
                $positions,
            ),
            'anchors' => [[
                'id' => 'reference-2026-10-01',
                'date' => '2026-10-01',
                'position' => $positions[0][0],
                'sources' => [$source],
            ]],
        ]],
    ];
};

return [
    'sources' => [[
        'id' => $source,
        'kind' => 'contributed',
        'title' => 'Day names, their order and the 2026-10-01 alignment for Nweh, Mundani (Wabane), "M\'Mouck" and "M\'Mock", as supplied by the atannex/nweh-mundani-core maintainer.',
        'recorded_on' => '2026-10-28',
        'status' => 'submitted',
        'notes' => 'Not yet traced to a documentary source, an oral-history recording or a named community custodian.',
    ]],

    'calendars' => [
        $calendar('nweh', 'Nweh', [
            ['ankoah', 'Ankoah'],
            ['anzoah', 'Anzoah'],
            ['alena', 'Alena'],
            ['amina', 'Amina'],
            ['afeah', 'Afeah'],
            ['agong', 'Agong'],
            ['aseih', 'Aseih'],
            ['alung', 'Alung'],
        ]),

        $calendar('mundani-wabane', 'Mundani (Wabane)', [
            ['agha', 'Aghà̧'],
            ['abu', 'Abùꞌ'],
            ['nge', 'Ngȩ̀'],
            ['kelu', 'Kèlu̧'],
            ['ewenesa', 'Èwenesa̧'],
            ['ewenela', 'Èwenelà̧'],
            ['nkwanye', 'Nkwanyȩ'],
            ['mendee', 'Mèndeè'],
        ], ['Mundae (Wabane)']),

        $calendar('mmouck', 'M\'Mouck', [
            ['nganga', 'Ngangà'],
            ['mbeqgnua', 'Mbeqgnúá'],
            ['mbeqleq', 'Mbeqlěq'],
            ['njoeengong', 'Njœêngong'],
            ['mbeqnkoeo', 'Mbeqńkœó'],
            ['njoeelekoer', 'Njœêlekœr̄'],
            ['faa', 'Faꞌà'],
            ['telang', 'Télǎng'],
        ], ['Mmouck']),

        $calendar('mmock', 'M\'Mock', [
            ['nganga', 'Ngangà'],
            ['betaagnua', 'Betaâgnúá'],
            ['mbeqleq', 'Mbeqlěq'],
            ['ngong', 'Ngong'],
            ['mbeqnkoeo', 'Mbeqńkœó'],
            ['njoeelekoer', 'Njœêlekœr̄'],
            ['faa', 'Faꞌà'],
            ['telang', 'Télǎng'],
        ], ['Mmock']),
    ],
];
