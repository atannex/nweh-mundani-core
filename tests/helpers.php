<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;

/**
 * Definition array for a single-cycle calendar of $length positions p1..pN,
 * anchored so $anchorDate is position $anchorPosition.
 *
 * @param  array<string, mixed>  $overrides  merged into the calendar
 * @param  array<string, mixed>  $cycle  merged into the cycle
 * @return array<string, mixed>
 */
function cyclicCalendar(string $id = 'test', int $length = 8, string $anchorDate = '2026-10-01', int $anchorPosition = 1, array $overrides = [], array $cycle = []): array
{
    return array_replace([
        'id' => $id,
        'version' => '1.0.0',
        'name' => ucfirst($id),
        'driver' => 'cyclic',
        'cycles' => [array_replace([
            'id' => 'days',
            'positions' => array_map(static fn (int $i): array => ['id' => "p{$i}", 'label' => "P{$i}"], range(1, $length)),
            'anchors' => [['id' => 'a1', 'date' => $anchorDate, 'position' => "p{$anchorPosition}"]],
        ], $cycle)],
    ], $overrides);
}

/** @param array<string, mixed> $data */
function definition(array $data, ?string $tenant = null): CalendarDefinition
{
    return app(CalendarFactory::class)->fromArray($data, $tenant);
}
