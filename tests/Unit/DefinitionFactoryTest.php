<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Kernel\CalendarValidator;

it('round-trips through its canonical array form', function () {
    $definition = definition(cyclicCalendar(length: 6, overrides: ['metadata' => ['region' => 'Somewhere', 'extra' => ['note' => 'x']]], cycle: ['exceptions' => [['id' => 'e', 'date' => '2026-10-03', 'position' => 'p1']]]));
    $again = definition($definition->toArray());

    expect($again->contentHash())->toBe($definition->contentHash())
        ->and($again->toArray())->toBe($definition->toArray());
});

it('collects every structural violation in one exception', function () {
    try {
        app(CalendarFactory::class)->fromArray([
            'id' => 'Bad Id',
            'version' => 'one',
            'cycles' => [[
                'id' => 'days',
                'length' => 3,
                'positions' => [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'A again']],
                'anchors' => [['id' => 'x', 'date' => '2026-02-30', 'position' => 'a']],
            ]],
        ]);
        $this->fail('expected InvalidDefinition');
    } catch (InvalidDefinition $e) {
        $text = $e->getMessage();
        expect($text)->toContain('calendar identifier')
            ->toContain('semantic version')
            ->toContain('name')
            ->toContain('declares 3')
            ->toContain('no day 30');
    }
});

it('rejects positions listed twice, ordinals out of place and missing anchors', function (array $cycle, string $message) {
    expect(fn () => definition(cyclicCalendar(cycle: $cycle)))->toThrow(InvalidDefinition::class, $message);
})->with([
    'duplicate position' => [['positions' => [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'B']], 'anchors' => [['id' => 'x', 'date' => '2026-01-01', 'position' => 'a']]], 'twice'],
    'wrong ordinal' => [['positions' => [['id' => 'a', 'label' => 'A', 'ordinal' => 2]]], 'ordinal'],
    'no anchors' => [['anchors' => []], 'anchors'],
    'no positions' => [['positions' => []], 'positions'],
]);

it('flags anchors naming unknown positions and unknown drivers', function () {
    $validator = app(CalendarValidator::class);

    expect($validator->validate(definition(cyclicCalendar(cycle: ['anchors' => [['id' => 'x', 'date' => '2026-01-01', 'position' => 'nope']]])))->passes())->toBeFalse()
        ->and($validator->validate(definition(cyclicCalendar(overrides: ['driver' => 'lunar-guess'])))->errors[0]->message)->toContain('no driver');
});

it('reports unverified assumptions as warnings, separate from validity', function () {
    $report = app(CalendarValidator::class)->validate(definition(cyclicCalendar(overrides: ['metadata' => ['assumptions' => [['id' => 'gapless', 'statement' => 'No days were skipped.']]]])));

    expect($report->passes())->toBeTrue()
        ->and(array_map('strval', $report->warnings))->toContain('metadata.assumptions.gapless: No days were skipped. (status: draft)');
});

it('bounds free-form metadata', function () {
    $extra = array_combine(array_map(fn ($i) => "k{$i}", range(1, 51)), range(1, 51));

    expect(fn () => definition(cyclicCalendar(overrides: ['metadata' => ['extra' => $extra]])))->toThrow(InvalidDefinition::class, 'at most 50')
        ->and(fn () => definition(cyclicCalendar(overrides: ['metadata' => ['extra' => ['k' => ['nested']]]])))->toThrow(InvalidDefinition::class, 'scalar');
});

it('defaults code-registered definitions to published', function () {
    expect(definition(cyclicCalendar())->version->status)->toBe(VersionStatus::Published);
});
