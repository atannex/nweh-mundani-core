<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;

/**
 * The outcome of a conversion, including the cases where there is no
 * single answer. Calendar::fromCivil() unwraps it; compare() keeps it.
 */
final readonly class CalendarDateResult
{
    /**
     * @param  list<CalendarDate>  $alternatives  Other candidates when ambiguous.
     * @param  list<string>  $notes  Human-readable explanation of anything short of Resolved.
     */
    public function __construct(
        public string $calendar,
        public CivilDate $civil,
        public ConversionStatus $status,
        public ?CalendarDate $date = null,
        public array $alternatives = [],
        public array $notes = [],
    ) {}

    public function isResolved(): bool
    {
        return $this->status === ConversionStatus::Resolved;
    }

    public function hasDate(): bool
    {
        return $this->date !== null;
    }

    /**
     * The calendar date, for Resolved and Partial results.
     *
     * @throws DateOutOfRange|UnsupportedOperation
     */
    public function date(): CalendarDate
    {
        if ($this->date !== null && in_array($this->status, [ConversionStatus::Resolved, ConversionStatus::Partial], true)) {
            return $this->date;
        }

        $reason = $this->notes === [] ? $this->status->value : implode(' ', $this->notes);

        throw match ($this->status) {
            ConversionStatus::OutOfRange => DateOutOfRange::forCalendar($this->calendar, $this->civil->toString(), $reason),
            default => UnsupportedOperation::forCalendar($this->calendar, 'converting '.$this->civil, $reason),
        };
    }
}
