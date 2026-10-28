<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers\Arithmetic;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\DriverKind;
use InvalidArgumentException;

/**
 * The arithmetic Hebrew calendar (lunisolar): 19-year cycle with 7 leap
 * years of 13 months, molad-based new years and the four postponement
 * rules. Entirely rule-based, so it is exact. Formulas: Dershowitz &
 * Reingold, ch. 8. Supported from 1 Tishri AM 1.
 *
 * Month numbers follow D&R (Nisan = 1 … Elul = 6, Tishri = 7 … Shevat = 11,
 * Adar = 12, Adar II = 13); the year starts at Tishri. Identifiers: nisan,
 * iyyar, sivan, tammuz, av, elul, tishri, marheshvan, kislev, tevet, shevat,
 * adar (common years), adar-i and adar-ii (leap years).
 *
 * Adding years maps Adar of a common year to Adar II of a leap year, and
 * Adar I or Adar II to Adar of a common year.
 */
final class HebrewDriver extends ArithmeticCalendarDriver
{
    public const string KEY = 'lunisolar-hebrew';

    /** R.D. of 1 Tishri AM 1 (Julian −3760-10-07, astronomical). */
    public const int EPOCH = -1373427;

    private const array IDS = [1 => 'nisan', 2 => 'iyyar', 3 => 'sivan', 4 => 'tammuz', 5 => 'av', 6 => 'elul', 7 => 'tishri', 8 => 'marheshvan', 9 => 'kislev', 10 => 'tevet', 11 => 'shevat'];

    /** @var array<int, int> year => fixed new year (bounded memo) */
    private array $newYears = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function kind(): DriverKind
    {
        return DriverKind::Lunisolar;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function parameterSpec(): array
    {
        return [];
    }

    public function monthIds(): array
    {
        return [...array_values(self::IDS), 'adar', 'adar-i', 'adar-ii'];
    }

    protected function firstFixed(CalendarDefinition $definition): int
    {
        return self::EPOCH;
    }

    protected function commonMonths(): int
    {
        return 12;
    }

    protected function leapMonths(): int
    {
        return 13;
    }

    /** @return non-empty-list<int> */
    protected function monthOrder(CalendarDefinition $definition, int $year): array
    {
        return [...range(7, $this->isLeapYear($definition, $year) ? 13 : 12), ...range(1, 6)];
    }

    protected function monthId(CalendarDefinition $definition, int $year, int $month): string
    {
        return match (true) {
            $month === 13 => 'adar-ii',
            $month === 12 => $this->isLeapYear($definition, $year) ? 'adar-i' : 'adar',
            default => self::IDS[$month] ?? throw new InvalidArgumentException("Month {$month} does not exist in the Hebrew calendar."),
        };
    }

    protected function equivalentMonth(CalendarDefinition $definition, int $fromYear, int $month, int $toYear): int
    {
        $fromLeap = $this->isLeapYear($definition, $fromYear);
        $toLeap = $this->isLeapYear($definition, $toYear);

        return match (true) {
            $month === 12 && ! $fromLeap && $toLeap => 13,  // Adar → Adar II
            $month === 13 && ! $toLeap => 12,               // Adar II → Adar
            default => $month,                               // Adar I → Adar keeps 12
        };
    }

    protected function isLeapYear(CalendarDefinition $definition, int $year): bool
    {
        return self::mod(7 * $year + 1, 19) < 7;
    }

    protected function daysInMonth(CalendarDefinition $definition, int $year, int $month): int
    {
        $short = in_array($month, [2, 4, 6, 10, 13], true)
            || ($month === 12 && ! $this->isLeapYear($definition, $year))
            || ($month === 8 && ! in_array($this->daysInYear($year), [355, 385], true))
            || ($month === 9 && in_array($this->daysInYear($year), [353, 383], true));

        return $short ? 29 : 30;
    }

    protected function toFixed(CalendarDefinition $definition, int $year, int $month, int $day): int
    {
        $days = $this->newYear($year) + $day - 1;
        $last = $this->isLeapYear($definition, $year) ? 13 : 12;

        if ($month < 7) {
            for ($m = 7; $m <= $last; $m++) {
                $days += $this->daysInMonth($definition, $year, $m);
            }

            for ($m = 1; $m < $month; $m++) {
                $days += $this->daysInMonth($definition, $year, $m);
            }
        } else {
            for ($m = 7; $m < $month; $m++) {
                $days += $this->daysInMonth($definition, $year, $m);
            }
        }

        return $days;
    }

    protected function fromFixed(CalendarDefinition $definition, int $fixed): array
    {
        // The mean-year estimate is within one year of the truth; settle it
        // by comparing actual new-year days.
        $year = self::div(98496 * ($fixed - self::EPOCH), 35975351);

        while ($this->newYear($year + 1) <= $fixed) {
            $year++;
        }

        while ($year > 1 && $this->newYear($year) > $fixed) {
            $year--;
        }
        $month = $fixed < $this->toFixed($definition, $year, 1, 1) ? 7 : 1;

        while ($fixed > $this->toFixed($definition, $year, $month, $this->daysInMonth($definition, $year, $month))) {
            $month++;
        }

        return [$year, $month, $fixed - $this->toFixed($definition, $year, $month, 1) + 1];
    }

    private function daysInYear(int $year): int
    {
        return $this->newYear($year + 1) - $this->newYear($year);
    }

    private function newYear(int $year): int
    {
        if (isset($this->newYears[$year])) {
            return $this->newYears[$year];
        }

        if (count($this->newYears) > 4096) {
            $this->newYears = [];
        }

        return $this->newYears[$year] = self::EPOCH + self::elapsedDays($year) + self::yearLengthCorrection($year);
    }

    /** Days from the epoch to the molad of Tishri, with the first postponement. */
    private static function elapsedDays(int $year): int
    {
        $months = self::div(235 * $year - 234, 19);
        $parts = 12084 + 13753 * $months;
        $days = 29 * $months + self::div($parts, 25920);

        return self::mod(3 * ($days + 1), 7) < 3 ? $days + 1 : $days;
    }

    /** The remaining postponements, which keep year lengths within 353–355 / 383–385 days. */
    private static function yearLengthCorrection(int $year): int
    {
        $previous = self::elapsedDays($year - 1);
        $current = self::elapsedDays($year);
        $next = self::elapsedDays($year + 1);

        return match (true) {
            $next - $current === 356 => 2,
            $current - $previous === 382 => 1,
            default => 0,
        };
    }
}
