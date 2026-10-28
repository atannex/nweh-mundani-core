<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * One civil date seen through several calendars. A calendar that cannot
 * represent the date still appears, with a result explaining why.
 *
 * @implements IteratorAggregate<string, CalendarDateResult>
 */
final readonly class CalendarDateComparison implements Countable, IteratorAggregate
{
    /** @param array<string, CalendarDateResult> $results Keyed by calendar identifier, in the order asked. */
    public function __construct(
        public CivilDate $civil,
        public array $results,
    ) {}

    public function get(string $calendar): ?CalendarDateResult
    {
        return $this->results[$calendar] ?? null;
    }

    /**
     * Primary-cycle labels per calendar (null where the date did not resolve).
     *
     * @return array<string, string|null>
     */
    public function labels(?string $locale = null): array
    {
        return array_map(static fn (CalendarDateResult $r): ?string => $r->date?->label($locale), $this->results);
    }

    public function count(): int
    {
        return count($this->results);
    }

    /** @return ArrayIterator<string, CalendarDateResult> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->results);
    }
}
