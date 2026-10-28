<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\UnknownRuleType;
use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;

/** Dates in a range on which a single-rule observance occurs. */
function ruleDates(array $rule, string $from, string $to, array $calendar = []): array
{
    $manager = app(CalendarManager::class);
    $manager->register(cyclicCalendar('rules', overrides: $calendar));
    config()->set('nweh-mundani.observances', []);
    app('nweh-mundani.static-observances')->save(app(ObservanceFactory::class)->fromArray([
        'id' => 'o', 'calendar' => 'rules', 'name' => 'O', 'category' => 'custom', 'rules' => [$rule],
    ]));

    return array_keys($manager->calendar('rules')->observancesBetween($from, $to));
}

it('matches fixed dates and yearly month-days, February 29 only in leap years', function () {
    expect(ruleDates(['type' => 'fixed-date', 'parameters' => ['date' => '2026-10-05']], '2026-10-01', '2026-10-31'))->toBe(['2026-10-05'])
        ->and(ruleDates(['type' => 'fixed-date', 'parameters' => ['month' => 2, 'day' => 29]], '2023-01-01', '2028-12-31'))->toBe(['2024-02-29', '2028-02-29']);
});

it('matches explicit historical occurrences without extrapolating', function () {
    expect(ruleDates(['type' => 'occurrences', 'parameters' => ['dates' => ['2026-10-02', '2026-10-20']]], '2026-10-01', '2026-12-31'))->toBe(['2026-10-02', '2026-10-20']);
});

it('matches cycle positions', function () {
    expect(ruleDates(['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p3']]], '2026-10-01', '2026-10-20'))->toBe(['2026-10-03', '2026-10-11', '2026-10-19']);
});

it('matches daily, monthly and yearly recurrences with counts and skipped months', function () {
    expect(ruleDates(['type' => 'recurrence', 'parameters' => ['frequency' => 'daily', 'interval' => 10, 'start' => '2026-10-01', 'count' => 3]], '2026-09-01', '2026-12-31'))->toBe(['2026-10-01', '2026-10-11', '2026-10-21'])
        ->and(ruleDates(['type' => 'recurrence', 'parameters' => ['frequency' => 'monthly', 'start' => '2026-01-31', 'count' => 3]], '2026-01-01', '2026-12-31'))->toBe(['2026-01-31', '2026-03-31', '2026-05-31'])
        ->and(ruleDates(['type' => 'recurrence', 'parameters' => ['frequency' => 'yearly', 'interval' => 2, 'start' => '2026-10-01']], '2026-01-01', '2031-12-31'))->toBe(['2026-10-01', '2028-10-01', '2030-10-01']);
});

it('resolves relative rules: nth cycle position from a yearly base, plus an offset', function () {
    // Base 1 October each year; 2nd p1 on/after it is 9 October 2026; +1 day.
    $rule = ['type' => 'relative', 'parameters' => ['base' => ['month' => 10, 'day' => 1], 'cycle' => 'days', 'position' => 'p1', 'occurrence' => 2, 'offset' => 1]];

    $dates = ruleDates($rule, '2026-01-01', '2027-12-31');
    // 2027-10-01 is 365 days after the anchor: 365 mod 8 = 5 → p6; next p1 on/after is 10-04, the second is 10-12; +1 → 10-13.
    expect($dates)->toBe(['2026-10-10', '2027-10-13']);

    $before = ['type' => 'relative', 'parameters' => ['base' => ['date' => '2026-10-20'], 'cycle' => 'days', 'position' => 'p1', 'occurrence' => -1]];
    expect(ruleDates($before, '2026-10-01', '2026-10-31'))->toBe(['2026-10-17']);
});

it('honours rule effective ranges', function () {
    $rule = ['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']], 'effective' => ['from' => '2026-10-05', 'until' => '2026-10-20']];

    expect(ruleDates($rule, '2026-10-01', '2026-10-31'))->toBe(['2026-10-09', '2026-10-17']);
});

it('rejects unknown rule types instead of ignoring them', function () {
    expect(fn () => app(RuleRegistry::class)->parse(['type' => 'php-eval', 'parameters' => ['code' => 'phpinfo()']]))->toThrow(UnknownRuleType::class);
});

it('rejects unknown and malformed parameters, reporting all of them', function () {
    try {
        app(RuleRegistry::class)->parse(['type' => 'recurrence', 'parameters' => ['frequency' => 'hourly', 'start' => 'soon', 'interval' => 0, 'sql' => 'DROP TABLE x']]);
        $this->fail('expected InvalidDefinition');
    } catch (InvalidDefinition $e) {
        $paths = array_map(fn ($v) => $v->path, $e->violations);
        expect($paths)->toContain('parameters.sql', 'parameters.frequency', 'parameters.interval', 'parameters.start');
    }
});

it('only accepts real RuleHandler classes as handlers', function () {
    expect(fn () => app(RuleRegistry::class)->register(stdClass::class))->toThrow(UnsafeRegistration::class)
        ->and(fn () => app(RuleRegistry::class)->register('Not\\A\\Class'))->toThrow(UnsafeRegistration::class);
});

it('checks cycle and position references against the calendar', function () {
    $rule = app(RuleRegistry::class)->parse(['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p99']]]);

    expect(app(RuleRegistry::class)->validateAgainst($rule, definition(cyclicCalendar())))->not->toBeEmpty();
});
