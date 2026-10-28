<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Arithmetic;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\DriverKind;

/**
 * Twelve-month solar calendars with a fixed leap rule:
 *
 *   rule = gregorian (default)  leap if divisible by 4, except centuries not divisible by 400
 *   rule = julian               leap if divisible by 4
 *
 * Both are proleptic and use astronomical year numbering (year 0 = 1 BCE),
 * like CivilDate. Formulas: Dershowitz & Reingold, Calendrical Calculations
 * (3rd ed.), ch. 2–3. Month identifiers are m1…m12; names come from the
 * definition.
 */
final class SolarDriver extends ArithmeticCalendarDriver
{
    public const string KEY = 'solar';

    /** R.D. of Julian 0001-01-01 (= Gregorian 0000-12-30). */
    private const int JULIAN_EPOCH = -1;

    public function key(): string
    {
        return self::KEY;
    }

    public function kind(): DriverKind
    {
        return DriverKind::Solar;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function parameterSpec(): array
    {
        return ['rule' => ['gregorian', 'julian']];
    }

    public function monthIds(): array
    {
        return array_map(static fn (int $m): string => "m{$m}", range(1, 12));
    }

    protected function monthId(CalendarDefinition $definition, int $year, int $month): string
    {
        return "m{$month}";
    }

    protected function isLeapYear(CalendarDefinition $definition, int $year): bool
    {
        return $this->julian($definition) ? self::mod($year, 4) === 0 : CivilDate::leap($year);
    }

    protected function daysInMonth(CalendarDefinition $definition, int $year, int $month): int
    {
        return match ($month) {
            2 => $this->isLeapYear($definition, $year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    protected function toFixed(CalendarDefinition $definition, int $year, int $month, int $day): int
    {
        if (! $this->julian($definition)) {
            return (new CivilDate($year, $month, $day))->dayNumber + self::UNIX_EPOCH_RD;
        }

        return self::JULIAN_EPOCH - 1 + 365 * ($year - 1) + self::div($year - 1, 4) + self::div(367 * $month - 362, 12)
            + ($month <= 2 ? 0 : ($this->isLeapYear($definition, $year) ? -1 : -2)) + $day;
    }

    protected function fromFixed(CalendarDefinition $definition, int $fixed): array
    {
        if (! $this->julian($definition)) {
            $civil = CivilDate::fromDayNumber($fixed - self::UNIX_EPOCH_RD);

            return [$civil->year, $civil->month, $civil->day];
        }

        // Astronomical year directly (D&R's "approx" before their no-year-zero adjustment).
        $year = self::div(4 * ($fixed - self::JULIAN_EPOCH) + 1464, 1461);
        $prior = $fixed - $this->toFixed($definition, $year, 1, 1);
        $correction = $fixed < $this->toFixed($definition, $year, 3, 1) ? 0 : ($this->isLeapYear($definition, $year) ? 1 : 2);
        $month = self::div(12 * ($prior + $correction) + 373, 367);

        return [$year, $month, $fixed - $this->toFixed($definition, $year, $month, 1) + 1];
    }

    private function julian(CalendarDefinition $definition): bool
    {
        return $this->setting($definition, 'rule') === 'julian';
    }
}
