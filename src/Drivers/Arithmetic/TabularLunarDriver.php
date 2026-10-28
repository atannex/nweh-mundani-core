<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Arithmetic;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\DriverKind;

/**
 * The tabular (arithmetic) Islamic calendar: twelve alternating 30/29-day
 * months, a 30-year cycle with 11 leap years (years 2, 5, 7, 10, 13, 16, 18,
 * 21, 24, 26 and 29, when Dhu al-Hijjah has 30 days).
 *
 *   epoch = civil (default)    Friday 16 July 622 (Julian) — R.D. 227015
 *   epoch = astronomical       Thursday 15 July 622 (Julian) — R.D. 227014
 *
 * This is the rule-based calendar, NOT an observational one: months that
 * begin with the sighting of the crescent, or by a national authority's
 * announcement, can differ from it by a day or two. It never claims to
 * predict those. Formulas: Dershowitz & Reingold, ch. 6. Supported from
 * year 1, day 1. Month identifiers are m1…m12.
 */
final class TabularLunarDriver extends ArithmeticCalendarDriver
{
    public const string KEY = 'lunar-tabular';

    public function key(): string
    {
        return self::KEY;
    }

    public function kind(): DriverKind
    {
        return DriverKind::Lunar;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function parameterSpec(): array
    {
        return ['epoch' => ['civil', 'astronomical']];
    }

    public function monthIds(): array
    {
        return array_map(static fn (int $m): string => "m{$m}", range(1, 12));
    }

    protected function firstFixed(CalendarDefinition $definition): int
    {
        return $this->epoch($definition);
    }

    protected function monthId(CalendarDefinition $definition, int $year, int $month): string
    {
        return "m{$month}";
    }

    protected function isLeapYear(CalendarDefinition $definition, int $year): bool
    {
        return self::mod(14 + 11 * $year, 30) < 11;
    }

    protected function daysInMonth(CalendarDefinition $definition, int $year, int $month): int
    {
        return $month % 2 === 1 || ($month === 12 && $this->isLeapYear($definition, $year)) ? 30 : 29;
    }

    protected function toFixed(CalendarDefinition $definition, int $year, int $month, int $day): int
    {
        return $day + 29 * ($month - 1) + self::div(6 * $month - 1, 11) + ($year - 1) * 354 + self::div(3 + 11 * $year, 30) + $this->epoch($definition) - 1;
    }

    protected function fromFixed(CalendarDefinition $definition, int $fixed): array
    {
        $year = self::div(30 * ($fixed - $this->epoch($definition)) + 10646, 10631);
        $prior = $fixed - $this->toFixed($definition, $year, 1, 1);
        $month = self::div(11 * $prior + 330, 325);

        return [$year, $month, $fixed - $this->toFixed($definition, $year, $month, 1) + 1];
    }

    private function epoch(CalendarDefinition $definition): int
    {
        return $this->setting($definition, 'epoch') === 'astronomical' ? 227014 : 227015;
    }
}
