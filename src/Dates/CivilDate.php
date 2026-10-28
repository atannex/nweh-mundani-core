<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A day on the proleptic Gregorian calendar, with no time and no timezone.
 *
 * Arithmetic runs on an integer day number (days since 1970-01-01) using
 * Howard Hinnant's civil-from-days algorithms, so day offsets never pass
 * through seconds, daylight-saving shifts or floating point.
 */
final readonly class CivilDate implements JsonSerializable, Stringable
{
    public const int MIN_YEAR = -9999;

    public const int MAX_YEAR = 9999;

    public int $dayNumber;

    public function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException("Year {$year} is outside the supported range ".self::MIN_YEAR.'..'.self::MAX_YEAR.'.');
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month {$month} is not between 1 and 12.");
        }

        if ($day < 1 || $day > self::daysInMonthOf($year, $month)) {
            throw new InvalidArgumentException(sprintf('%04d-%02d has no day %d.', $year, $month, $day));
        }

        $this->dayNumber = self::daysFromCivil($year, $month, $day);
    }

    /**
     * Parse a strict ISO 8601 calendar date (YYYY-MM-DD, optional leading minus).
     */
    public static function parse(string $value): self
    {
        if (! preg_match('/^(-?\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException("[{$value}] is not a YYYY-MM-DD date.");
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * Accept the loose inputs public APIs take: a CivilDate, a YYYY-MM-DD
     * string, or an instant read in an explicit timezone.
     */
    public static function from(self|string|DateTimeInterface $value, DateTimeZone|string|null $timezone = null): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof DateTimeInterface => self::fromInstant($value, $timezone),
            default => self::parse($value),
        };
    }

    /**
     * The civil date an instant falls on in a given timezone. Without a
     * timezone the instant's own timezone is used; name one to be explicit.
     */
    public static function fromInstant(DateTimeInterface $instant, DateTimeZone|string|null $timezone = null): self
    {
        $local = DateTimeImmutable::createFromInterface($instant);

        if ($timezone !== null) {
            $local = $local->setTimezone(is_string($timezone) ? new DateTimeZone($timezone) : $timezone);
        }

        return new self((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    public static function fromDayNumber(int $dayNumber): self
    {
        [$y, $m, $d] = self::civilFromDays($dayNumber);

        return new self($y, $m, $d);
    }

    public function addDays(int $days): self
    {
        return $days === 0 ? $this : self::fromDayNumber($this->dayNumber + $days);
    }

    /**
     * Signed whole days from this date to another: positive when $other is later.
     */
    public function daysUntil(self $other): int
    {
        return $other->dayNumber - $this->dayNumber;
    }

    public function compareTo(self $other): int
    {
        return $this->dayNumber <=> $other->dayNumber;
    }

    public function equals(self $other): bool
    {
        return $this->dayNumber === $other->dayNumber;
    }

    public function isBefore(self $other): bool
    {
        return $this->dayNumber < $other->dayNumber;
    }

    public function isAfter(self $other): bool
    {
        return $this->dayNumber > $other->dayNumber;
    }

    public function isLeapYear(): bool
    {
        return self::leap($this->year);
    }

    public function daysInMonth(): int
    {
        return self::daysInMonthOf($this->year, $this->month);
    }

    /** ISO weekday: 1 = Monday … 7 = Sunday. */
    public function isoWeekday(): int
    {
        // 1970-01-01 was a Thursday (4).
        return (($this->dayNumber + 3) % 7 + 7) % 7 + 1;
    }

    /**
     * The same month and day in another year, or null when it does not exist
     * there (29 February outside leap years).
     */
    public function inYear(int $year): ?self
    {
        return $this->day <= self::daysInMonthOf($year, $this->month) ? new self($year, $this->month, $this->day) : null;
    }

    public function toDateTime(DateTimeZone|string $timezone = 'UTC'): DateTimeImmutable
    {
        $zone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;

        return (new DateTimeImmutable('now', $zone))->setDate($this->year, $this->month, $this->day)->setTime(0, 0);
    }

    public function toString(): string
    {
        return sprintf($this->year < 0 ? '-%04d-%02d-%02d' : '%04d-%02d-%02d', abs($this->year), $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public static function leap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function daysInMonthOf(int $year, int $month): int
    {
        return match ($month) {
            2 => self::leap($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** @return array{int, int, int} */
    private static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;

        return [$y + ($m <= 2 ? 1 : 0), $m, $d];
    }
}
