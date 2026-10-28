<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application;

use Atannex\NwehMundani\Core\Application\Data\CalendarDayData;
use Atannex\NwehMundani\Core\Application\Data\CalendarMonthData;
use Atannex\NwehMundani\Core\Application\Data\CalendarRangeData;
use Atannex\NwehMundani\Core\Application\Data\CalendarSummaryData;
use Atannex\NwehMundani\Core\Application\Data\CalendarYearData;
use Atannex\NwehMundani\Core\Application\Data\DayCycleData;
use Atannex\NwehMundani\Core\Application\Data\OccurrenceData;
use Atannex\NwehMundani\Core\Dates\CalendarDate;
use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Exceptions\RangeTooLarge;
use Atannex\NwehMundani\Core\Kernel\Calendar;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceOccurrence;
use Atannex\NwehMundani\Core\Rules\RuleContext;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Turns a resolved Calendar into frontend-ready read models in bulk.
 *
 * For any span (day, month, year, range) the definition is resolved once,
 * the visible observances are loaded once (one cached load, not one query
 * per day), each day is converted once, and the rule context of each day is
 * built once and reused when reschedules look at other days.
 */
final readonly class CalendarViewBuilder
{
    public function __construct(private int $maxRangeDays = 1830) {}

    public function summary(Calendar $calendar, CalendarStatus $status = CalendarStatus::Active): CalendarSummaryData
    {
        $definition = $calendar->definition();
        $context = $calendar->context();
        $locale = $context->locale;
        $localizer = $calendar->localizer();

        return new CalendarSummaryData(
            id: $definition->id(),
            name: $calendar->name(),
            original: $definition->name->original,
            language: $definition->name->language,
            scope: $definition->tenant() === '' ? 'global' : 'tenant',
            version: $definition->version->number,
            versionStatus: $definition->version->status->value,
            status: $status->value,
            driver: $definition->driver,
            driverKind: $calendar->driver()->kind()->value,
            capabilities: $calendar->capabilities()->toArray(),
            cycles: array_map(static fn (CalendarCycle $c): array => [
                'id' => $c->id,
                'name' => $localizer->label($c->name, Localizer::cycleKey($definition->id(), $c->id), $locale, $context),
                'length' => $c->length(),
                'continuity' => $c->continuity->value,
            ], $definition->cycles),
            verification: $definition->metadata->verification->value,
            unverifiedAssumptions: array_map(static fn ($a): array => $a->toArray(), $definition->metadata->unverifiedAssumptions()),
            region: $definition->metadata->region,
            tags: $definition->metadata->tags,
            effectiveFrom: $definition->effective->from?->toString(),
            effectiveUntil: $definition->effective->until?->toString(),
        );
    }

    public function day(Calendar $calendar, CivilDate $date, ?Authenticatable $viewer = null): CalendarDayData
    {
        return $this->days($calendar, $date, $date, $viewer)[0];
    }

    /** @return list<CalendarDayData> */
    public function days(Calendar $calendar, CivilDate $from, CivilDate $to, ?Authenticatable $viewer = null): array
    {
        $range = new CalendarDateRange($from, $to);

        if (count($range) > $this->maxRangeDays) {
            throw RangeTooLarge::days(count($range), $this->maxRangeDays);
        }

        $visible = $calendar->observances($viewer);
        $service = $calendar->observanceService();

        /** @var array<int, RuleContext> $contexts */
        $contexts = [];
        $contextFor = static function (CivilDate $day) use (&$contexts, $calendar): RuleContext {
            return $contexts[$day->dayNumber] ??= $calendar->ruleContext($day);
        };

        $days = [];

        foreach ($range as $day) {
            $result = $calendar->convert($day);
            $occurrences = [];

            if ($visible !== []) {
                $contexts[$day->dayNumber] ??= $calendar->ruleContext($day, $result->date);
                $occurrences = array_map(fn (ObservanceOccurrence $o): OccurrenceData => $this->occurrence($calendar, $o), $service->occurrences($visible, $contexts[$day->dayNumber], $contextFor));
            }

            $cycles = [];

            foreach ($result->date->cycles ?? [] as $reading) {
                $cycles[] = DayCycleData::from($reading, $calendar->localizer()->label(
                    $reading->position->label,
                    Localizer::positionKey($calendar->id(), $reading->cycleId, $reading->positionId()),
                    $calendar->context()->locale,
                    $calendar->context(),
                ));
            }

            $days[] = new CalendarDayData(
                calendar: $calendar->id(),
                version: $calendar->version(),
                date: $day->toString(),
                isoWeekday: $day->isoWeekday(),
                status: $result->status->value,
                certainty: $result->date?->certainty()?->value,
                cycles: $cycles,
                occurrences: $occurrences,
                components: $result->date === null ? [] : $result->date->components,
                notes: $result->notes,
                units: $this->units($calendar, $result->date),
            );
        }

        return $days;
    }

    /**
     * Named components of a converted date (the month of a year/month/day
     * calendar), labelled from the definition's names; the identifier
     * stands in when the definition names nothing.
     *
     * @return list<array{unit: string, id: string, number: int|null, label: string, original: string|null}>
     */
    private function units(Calendar $calendar, ?CalendarDate $date): array
    {
        if ($date === null) {
            return [];
        }

        $units = [];

        foreach ($date->identifiers as $unit => $id) {
            $name = $calendar->definition()->nameOf($unit, $id);

            $units[] = [
                'unit' => $unit,
                'id' => $id,
                'number' => $date->component($unit),
                'label' => $name === null ? $id : $calendar->localizer()->label($name->label, Localizer::nameKey($calendar->id(), $unit, $id), $calendar->context()->locale, $calendar->context()),
                'original' => $name?->label->original,
            ];
        }

        return $units;
    }

    public function range(Calendar $calendar, CivilDate $from, CivilDate $to, ?Authenticatable $viewer = null, CalendarStatus $status = CalendarStatus::Active): CalendarRangeData
    {
        return new CalendarRangeData($this->summary($calendar, $status), $from->toString(), $to->toString(), $this->days($calendar, $from, $to, $viewer));
    }

    public function month(Calendar $calendar, int $year, int $month, ?Authenticatable $viewer = null, CalendarStatus $status = CalendarStatus::Active): CalendarMonthData
    {
        self::assertMonth($year, $month);
        $start = new CivilDate($year, $month, 1);
        $end = new CivilDate($year, $month, $start->daysInMonth());

        return $this->monthFrom($this->summary($calendar, $status), $year, $month, $this->days($calendar, $start, $end, $viewer));
    }

    public function year(Calendar $calendar, int $year, ?Authenticatable $viewer = null, CalendarStatus $status = CalendarStatus::Active): CalendarYearData
    {
        self::assertMonth($year, 1);
        $summary = $this->summary($calendar, $status);
        $days = $this->days($calendar, new CivilDate($year, 1, 1), new CivilDate($year, 12, 31), $viewer);
        $months = [];
        $offset = 0;

        for ($month = 1; $month <= 12; $month++) {
            $length = CivilDate::daysInMonthOf($year, $month);
            $months[] = $this->monthFrom($summary, $year, $month, array_slice($days, $offset, $length));
            $offset += $length;
        }

        return new CalendarYearData(
            $summary,
            $year,
            CivilDate::leap($year),
            $months,
            $year > CivilDate::MIN_YEAR ? $year - 1 : null,
            $year < CivilDate::MAX_YEAR ? $year + 1 : null,
        );
    }

    public function occurrence(Calendar $calendar, ObservanceOccurrence $occurrence): OccurrenceData
    {
        $observance = $occurrence->observance;

        return new OccurrenceData(
            observance: $observance->id,
            calendar: $observance->calendar,
            name: $this->observanceName($calendar, $observance),
            original: $observance->name->original,
            category: $observance->category,
            date: $occurrence->date->toString(),
            scheduledFor: $occurrence->scheduledFor->toString(),
            state: $occurrence->state->value,
            reason: $occurrence->reason,
            visibility: $observance->visibility->value,
            verification: $observance->verification->value,
            sources: $observance->sources,
            priority: $observance->priority,
        );
    }

    public function observanceName(Calendar $calendar, Observance $observance, string $field = 'name'): string
    {
        $term = $field === 'description' ? ($observance->description ?? $observance->name) : $observance->name;

        return $calendar->localizer()->label($term, Localizer::observanceKey($observance->calendar, $observance->id, $field), $calendar->context()->locale, $calendar->context());
    }

    /** @param list<CalendarDayData> $days */
    private function monthFrom(CalendarSummaryData $summary, int $year, int $month, array $days): CalendarMonthData
    {
        $first = new CivilDate($year, $month, 1);
        $previous = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        $next = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];

        return new CalendarMonthData(
            calendar: $summary,
            year: $year,
            month: $month,
            start: $first->toString(),
            end: (new CivilDate($year, $month, $first->daysInMonth()))->toString(),
            firstIsoWeekday: $first->isoWeekday(),
            days: $days,
            previous: $previous[0] >= CivilDate::MIN_YEAR ? ['year' => $previous[0], 'month' => $previous[1]] : null,
            next: $next[0] <= CivilDate::MAX_YEAR ? ['year' => $next[0], 'month' => $next[1]] : null,
        );
    }

    private static function assertMonth(int $year, int $month): void
    {
        if ($year < CivilDate::MIN_YEAR || $year > CivilDate::MAX_YEAR || $month < 1 || $month > 12) {
            throw new InvalidArgumentException("{$year}-{$month} is not a supported year and month.");
        }
    }
}
