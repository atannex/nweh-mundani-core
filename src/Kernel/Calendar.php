<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Application\CalendarViewBuilder;
use Atannex\NwehMundani\Core\Application\Data\CalendarDayData;
use Atannex\NwehMundani\Core\Application\Data\CalendarMonthData;
use Atannex\NwehMundani\Core\Application\Data\CalendarRangeData;
use Atannex\NwehMundani\Core\Application\Data\CalendarSummaryData;
use Atannex\NwehMundani\Core\Application\Data\CalendarYearData;
use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CalendarDateFormatter;
use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CalendarDateResult;
use Atannex\NwehMundani\Core\Dates\CalendarPeriod;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Dates\ConversionStatus;
use Atannex\NwehMundani\Core\Domain\CalendarCapabilities;
use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Drivers\Contracts\CivilDateConvertible;
use Atannex\NwehMundani\Core\Drivers\Contracts\CycleAware;
use Atannex\NwehMundani\Core\Drivers\Contracts\PeriodEnumerable;
use Atannex\NwehMundani\Core\Drivers\Contracts\ReverseDateConvertible;
use Atannex\NwehMundani\Core\Exceptions\DateOutOfRange;
use Atannex\NwehMundani\Core\Exceptions\UnsupportedOperation;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceOccurrence;
use Atannex\NwehMundani\Core\Observances\ObservanceService;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One resolved calendar version bound to its driver and an explicit
 * context. This is what NwehMundani::calendar('nweh') returns.
 *
 * Dates may be given as CivilDate, 'YYYY-MM-DD' or DateTimeInterface; an
 * instant is read in the context's timezone, never the server's.
 */
final readonly class Calendar
{
    /** @param Closure(): ?Authenticatable $viewer */
    public function __construct(
        private CalendarDefinition $definition,
        private CalendarDriver $driver,
        private CalendarContext $context,
        private CalendarValidator $validator,
        private ObservanceService $observances,
        private Localizer $localizer,
        private Closure $viewer,
        private CalendarViewBuilder $views = new CalendarViewBuilder,
        private CalendarStatus $status = CalendarStatus::Active,
    ) {}

    /** Lifecycle status of the calendar this handle was resolved from. */
    public function status(): CalendarStatus
    {
        return $this->status;
    }

    /** Frontend-ready summary (identity, version, capabilities, verification). */
    public function summary(): CalendarSummaryData
    {
        return $this->views->summary($this, $this->status);
    }

    /** One day as a read model, with cycle readings and occurrences. */
    public function day(CivilDate|string|DateTimeInterface $date, ?Authenticatable $viewer = null): CalendarDayData
    {
        return $this->views->day($this, $this->civil($date), $viewer);
    }

    /** A civil month, day by day, ready to render. */
    public function month(int $year, int $month, ?Authenticatable $viewer = null): CalendarMonthData
    {
        return $this->views->month($this, $year, $month, $viewer, $this->status);
    }

    /** A civil year as twelve months, computed in one pass. */
    public function year(int $year, ?Authenticatable $viewer = null): CalendarYearData
    {
        return $this->views->year($this, $year, $viewer, $this->status);
    }

    /**
     * A month in the calendar's own reckoning (e.g. Tishri 5787), as the
     * civil days it spans. Needs a driver with reverse conversion and periods.
     *
     * @param  int|string  $month  month number or identifier (e.g. 'adar-ii')
     */
    public function nativeMonth(int $year, int|string $month, ?Authenticatable $viewer = null): CalendarRangeData
    {
        $period = $this->periodDriver();
        $first = $this->toCivil(['year' => $year, 'month' => $month, 'day' => 1])[0];
        $range = $period->periodContaining($this->definition, 'month', $first)->range;

        return $this->views->range($this, $range->start, $range->end, $viewer, $this->status);
    }

    /** Any inclusive range, up to nweh-mundani.queries.max_range_days. */
    public function days(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?Authenticatable $viewer = null): CalendarRangeData
    {
        return $this->views->range($this, $this->civil($from), $this->civil($to), $viewer, $this->status);
    }

    public function id(): string
    {
        return $this->definition->id();
    }

    public function name(?string $locale = null): string
    {
        return $this->localizer->label($this->definition->name, Localizer::calendarKey($this->id()), $locale ?? $this->context->locale, $this->context);
    }

    public function version(): string
    {
        return $this->definition->version->number;
    }

    public function definition(): CalendarDefinition
    {
        return $this->definition;
    }

    public function driver(): CalendarDriver
    {
        return $this->driver;
    }

    public function context(): CalendarContext
    {
        return $this->context;
    }

    public function capabilities(): CalendarCapabilities
    {
        return CalendarCapabilities::ofDriver($this->driver, $this->definition);
    }

    public function validate(): ValidationReport
    {
        return $this->validator->validate($this->definition);
    }

    /**
     * The calendar's representation of a civil date.
     *
     * @throws DateOutOfRange|UnsupportedOperation
     */
    public function fromCivil(CivilDate|string|DateTimeInterface $date): CalendarDate
    {
        return $this->convert($date)->date();
    }

    /** Like fromCivil(), but reports out-of-range and unsupported cases instead of throwing. */
    public function convert(CivilDate|string|DateTimeInterface $date): CalendarDateResult
    {
        $civil = $this->civil($date);

        if (! $this->driver instanceof CivilDateConvertible) {
            return new CalendarDateResult($this->id(), $civil, ConversionStatus::Unsupported, notes: ["Driver [{$this->driver->key()}] does not convert civil dates."]);
        }

        return $this->driver->fromCivil($this->definition, $civil);
    }

    /**
     * Every date in an inclusive range, keyed by YYYY-MM-DD.
     *
     * @return array<string, CalendarDateResult>
     */
    public function range(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to): array
    {
        $results = [];

        foreach (new CalendarDateRange($this->civil($from), $this->civil($to)) as $day) {
            $results[$day->toString()] = $this->convert($day);
        }

        return $results;
    }

    /**
     * Calendar components → civil date(s). Only drivers whose representation
     * names a single day support this; a cycle position alone does not.
     *
     * @param  array<string, int|string>  $components
     * @return list<CivilDate>
     *
     * @throws UnsupportedOperation
     */
    public function toCivil(array $components): array
    {
        if (! $this->driver instanceof ReverseDateConvertible) {
            throw UnsupportedOperation::forCalendar($this->id(), 'reverse conversion', 'its driver identifies the position of a recurring cycle on a civil date, which does not determine a single civil date. Use nextOccurrence() or occurrencesBetween() instead.');
        }

        return $this->driver->toCivil($this->definition, $components);
    }

    public function nextOccurrence(string $position, CivilDate|string|DateTimeInterface $from, ?string $cycle = null, bool $inclusive = true): ?CivilDate
    {
        return $this->cycleDriver()->nextOccurrence($this->definition, $cycle ?? $this->primaryCycle()->id, $position, $this->civil($from), $inclusive);
    }

    public function previousOccurrence(string $position, CivilDate|string|DateTimeInterface $from, ?string $cycle = null, bool $inclusive = true): ?CivilDate
    {
        return $this->cycleDriver()->previousOccurrence($this->definition, $cycle ?? $this->primaryCycle()->id, $position, $this->civil($from), $inclusive);
    }

    /** @return list<CivilDate> */
    public function occurrencesBetween(string $position, CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?string $cycle = null): array
    {
        return $this->cycleDriver()->occurrencesBetween($this->definition, $cycle ?? $this->primaryCycle()->id, $position, new CalendarDateRange($this->civil($from), $this->civil($to)));
    }

    public function periodContaining(CivilDate|string|DateTimeInterface $date, ?string $unit = null): CalendarPeriod
    {
        return $this->periodDriver()->periodContaining($this->definition, $unit ?? $this->primaryCycle()->id, $this->civil($date));
    }

    /** @return list<CalendarPeriod> */
    public function periodsBetween(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?string $unit = null): array
    {
        return $this->periodDriver()->periodsBetween($this->definition, $unit ?? $this->primaryCycle()->id, new CalendarDateRange($this->civil($from), $this->civil($to)));
    }

    /**
     * Every observance of this calendar the viewer may see, regardless of date.
     *
     * @return list<Observance>
     */
    public function observances(?Authenticatable $viewer = null): array
    {
        return $this->observances->visible($this->context, $this->id(), $viewer ?? ($this->viewer)());
    }

    /**
     * Observances taking place on a date: cancellations removed, reschedules
     * moved onto their new date, unpublished observances left out.
     *
     * @return list<Observance>
     */
    public function observancesOn(CivilDate|string|DateTimeInterface $date, ?Authenticatable $viewer = null): array
    {
        $civil = $this->civil($date);

        return $this->observances->matching($this->observances($viewer), $this->ruleContext($civil), $this->contextFactory());
    }

    /**
     * Occurrences on a date, including cancelled ones (flagged), so a
     * frontend can show "cancelled" instead of silently omitting the day.
     *
     * @return list<ObservanceOccurrence>
     */
    public function observanceOccurrencesOn(CivilDate|string|DateTimeInterface $date, ?Authenticatable $viewer = null): array
    {
        return $this->observances->occurrences($this->observances($viewer), $this->ruleContext($this->civil($date)), $this->contextFactory());
    }

    /**
     * Occurrences over a range, keyed by day. Visibility is checked once.
     *
     * @return array<string, list<ObservanceOccurrence>> Only days with at least one occurrence.
     */
    public function observanceOccurrencesBetween(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?Authenticatable $viewer = null): array
    {
        $visible = $this->observances($viewer);
        $found = [];

        if ($visible === []) {
            return [];
        }

        $contextFor = $this->contextFactory();

        foreach (new CalendarDateRange($this->civil($from), $this->civil($to)) as $day) {
            $occurrences = $this->observances->occurrences($visible, $contextFor($day), $contextFor);

            if ($occurrences !== []) {
                $found[$day->toString()] = $occurrences;
            }
        }

        return $found;
    }

    /**
     * Observances per day over a range (cancelled dates left out).
     *
     * @return array<string, list<Observance>> Only days with at least one observance.
     */
    public function observancesBetween(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?Authenticatable $viewer = null): array
    {
        $found = [];

        foreach ($this->observanceOccurrencesBetween($from, $to, $viewer) as $day => $occurrences) {
            $live = [];

            foreach ($occurrences as $occurrence) {
                if (! $occurrence->isCancelled()) {
                    $live[$occurrence->observance->id] = $occurrence->observance;
                }
            }

            if ($live !== []) {
                $found[$day] = array_values($live);
            }
        }

        return $found;
    }

    /**
     * Rule context for a date, reusing an already-converted calendar date
     * when the caller has one (bulk builders do).
     */
    public function ruleContext(CivilDate $date, ?CalendarDate $calendarDate = null): RuleContext
    {
        $driver = $this->driver;
        $definition = $this->definition;
        $resolve = fn (CivilDate $d): ?CalendarDate => $this->convert($d)->date;

        $next = static fn (string $cycle, string $position, CivilDate $from, bool $inclusive): ?CivilDate => $driver instanceof CycleAware
            ? $driver->nextOccurrence($definition, $cycle, $position, $from, $inclusive)
            : null;

        $previous = static fn (string $cycle, string $position, CivilDate $from, bool $inclusive): ?CivilDate => $driver instanceof CycleAware
            ? $driver->previousOccurrence($definition, $cycle, $position, $from, $inclusive)
            : null;

        return new RuleContext($definition, $date, $calendarDate ?? $resolve($date), $resolve, $next, $previous);
    }

    /** @return Closure(CivilDate): RuleContext */
    public function contextFactory(): Closure
    {
        return fn (CivilDate $day): RuleContext => $this->ruleContext($day);
    }

    public function observanceService(): ObservanceService
    {
        return $this->observances;
    }

    public function localizer(): Localizer
    {
        return $this->localizer;
    }

    /** The viewer used when none is passed: the default guard's user, if any. */
    public function defaultViewer(): ?Authenticatable
    {
        return ($this->viewer)();
    }

    public function format(CalendarDate $date, string $pattern = CalendarDateFormatter::DEFAULT_PATTERN, ?string $locale = null): string
    {
        return (new CalendarDateFormatter($this->localizer, $this->definition))->format($date, $locale ?? $this->context->locale, $pattern, $this->context);
    }

    /** Shortcut: the primary cycle's label on a date, in the context locale. */
    public function label(CivilDate|string|DateTimeInterface $date, ?string $locale = null): ?string
    {
        $result = $this->convert($date);

        if ($result->date === null) {
            return null;
        }

        // Year/month/day calendars without cycles have no cycle label: show their own date.
        $pattern = $result->date->cycles === [] && isset($result->date->components['year'])
            ? '{component:day} {unit:month} {component:year}'
            : CalendarDateFormatter::DEFAULT_PATTERN;

        return $this->format($result->date, $pattern, $locale);
    }

    public function civil(CivilDate|string|DateTimeInterface $date): CivilDate
    {
        return CivilDate::from($date, $this->context->timezone);
    }

    private function cycleDriver(): CycleAware
    {
        return $this->driver instanceof CycleAware
            ? $this->driver
            : throw UnsupportedOperation::forCalendar($this->id(), 'cycle queries', "driver [{$this->driver->key()}] is not cycle-aware.");
    }

    private function periodDriver(): PeriodEnumerable
    {
        return $this->driver instanceof PeriodEnumerable
            ? $this->driver
            : throw UnsupportedOperation::forCalendar($this->id(), 'period enumeration', "driver [{$this->driver->key()}] does not enumerate periods.");
    }

    private function primaryCycle(): CalendarCycle
    {
        return $this->definition->primaryCycle()
            ?? throw UnsupportedOperation::forCalendar($this->id(), 'cycle queries', 'the calendar defines no cycles.');
    }
}
