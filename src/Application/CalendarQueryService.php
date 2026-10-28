<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application;

use Atannex\NwehMundani\Core\Application\Data\CalendarDayData;
use Atannex\NwehMundani\Core\Application\Data\CalendarDetailsData;
use Atannex\NwehMundani\Core\Application\Data\CalendarMonthData;
use Atannex\NwehMundani\Core\Application\Data\CalendarRangeData;
use Atannex\NwehMundani\Core\Application\Data\CalendarSummaryData;
use Atannex\NwehMundani\Core\Application\Data\CalendarYearData;
use Atannex\NwehMundani\Core\Application\Data\ComparisonData;
use Atannex\NwehMundani\Core\Application\Data\CycleData;
use Atannex\NwehMundani\Core\Application\Data\HistoryData;
use Atannex\NwehMundani\Core\Application\Data\ObservanceData;
use Atannex\NwehMundani\Core\Application\Data\OccurrenceData;
use Atannex\NwehMundani\Core\Application\Data\SourceData;
use Atannex\NwehMundani\Core\Application\Data\TranslationsData;
use Atannex\NwehMundani\Core\Dates\CalendarDateRange;
use Atannex\NwehMundani\Core\Dates\CalendarPeriod;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarAnchor;
use Atannex\NwehMundani\Core\Domain\CalendarException;
use Atannex\NwehMundani\Core\Domain\CyclePosition;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\CalendarUnavailable;
use Atannex\NwehMundani\Core\Exceptions\RangeTooLarge;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\SourceCatalog;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Governance\Verification;
use Atannex\NwehMundani\Core\Kernel\Calendar;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarDiagnostics;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\DiagnosticCheck;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Localization\TranslationCatalog;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceGate;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\VersionSummary;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Every read a frontend needs, returning serialisable read models.
 *
 * Each method takes an optional context (tenant, locale, timezone; default:
 * the current one from the TenantResolver) and an optional viewer (default:
 * the authenticated user), so a controller passes only what the request
 * carries. Nothing here writes, and nothing returns data the viewer may not
 * see: restricted observances and sources go through the same gates as
 * everywhere else, and actor names in history need nweh-mundani.view-audit.
 */
final readonly class CalendarQueryService
{
    public function __construct(
        private CalendarManager $manager,
        private CalendarRepository $repository,
        private AuditTrail $audit,
        private SourceCatalog $sources,
        private ObservanceGate $gate,
        private CalendarAuthorizer $authorizer,
        private CalendarDiagnostics $diagnostics,
        private ?TranslationCatalog $translations = null,
        private int $maxRangeDays = 1830,
        private int $maxCalendars = 50,
    ) {}

    /** @return list<CalendarSummaryData> */
    public function listCalendars(bool $includeInactive = false, ?CalendarContext $context = null): array
    {
        return array_values(array_map(
            static fn (Calendar $c): CalendarSummaryData => $c->summary(),
            $this->manager($context)->calendars($includeInactive),
        ));
    }

    /**
     * Published and superseded versions are public history. Versions still
     * in the workflow (draft, under review, approved) are only shown to
     * viewers who may manage or review the calendar; to anyone else they do
     * not exist (CalendarNotFound), so their existence is not revealed.
     *
     * @throws CalendarNotFound|CalendarUnavailable
     */
    public function getCalendar(string $calendarId, ?string $version = null, ?CalendarContext $context = null, Authenticatable|SystemActor|null $viewer = null): CalendarDetailsData
    {
        $calendar = $this->calendar($calendarId, $context, $version, includeInactive: $version !== null);
        $viewer ??= $calendar->defaultViewer();

        if (! $calendar->definition()->version->status->isReleased() && ! $this->mayReview($viewer, $calendar)) {
            throw CalendarNotFound::forIdentifier($calendarId, $calendar->context()->tenant, $version);
        }

        return $this->details($calendar, $viewer instanceof SystemActor ? null : $viewer);
    }

    /** A specific stored or registered version, including superseded ones. */
    public function getDefinitionVersion(string $calendarId, string $version, ?CalendarContext $context = null, Authenticatable|SystemActor|null $viewer = null): CalendarDetailsData
    {
        return $this->getCalendar($calendarId, $version, $context, $viewer);
    }

    /** @return list<string> */
    public function capabilities(string $calendarId, ?CalendarContext $context = null): array
    {
        return $this->calendar($calendarId, $context)->capabilities()->toArray();
    }

    public function day(string $calendarId, CivilDate|string|DateTimeInterface $date, ?CalendarContext $context = null, ?Authenticatable $viewer = null): CalendarDayData
    {
        return $this->calendar($calendarId, $context)->day($date, $viewer);
    }

    public function range(string $calendarId, CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?CalendarContext $context = null, ?Authenticatable $viewer = null): CalendarRangeData
    {
        return $this->calendar($calendarId, $context)->days($from, $to, $viewer);
    }

    public function month(string $calendarId, int $year, int $month, ?CalendarContext $context = null, ?Authenticatable $viewer = null): CalendarMonthData
    {
        return $this->calendar($calendarId, $context)->month($year, $month, $viewer);
    }

    /**
     * A month in the calendar's own reckoning (year/month calendars such as
     * Hebrew or Hijri), as the civil days it spans.
     */
    public function nativeMonth(string $calendarId, int $year, int|string $month, ?CalendarContext $context = null, ?Authenticatable $viewer = null): CalendarRangeData
    {
        return $this->calendar($calendarId, $context)->nativeMonth($year, $month, $viewer);
    }

    public function year(string $calendarId, int $year, ?CalendarContext $context = null, ?Authenticatable $viewer = null): CalendarYearData
    {
        return $this->calendar($calendarId, $context)->year($year, $viewer);
    }

    /**
     * One civil date in several calendars (all visible ones when $calendarIds is null).
     *
     * @param  list<string>|null  $calendarIds
     */
    public function compare(CivilDate|string|DateTimeInterface $date, ?array $calendarIds = null, ?CalendarContext $context = null, ?Authenticatable $viewer = null): ComparisonData
    {
        return $this->compareRange($date, $date, $calendarIds, $context, $viewer)[0];
    }

    /**
     * @param  list<string>|null  $calendarIds
     * @return list<ComparisonData> one entry per day
     */
    public function compareRange(CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?array $calendarIds = null, ?CalendarContext $context = null, ?Authenticatable $viewer = null): array
    {
        $manager = $this->manager($context);
        $calendars = $calendarIds === null
            ? $manager->calendars()
            : array_combine($calendarIds, array_map(static fn (string $id): Calendar => $manager->calendar($id), $calendarIds));

        if (count($calendars) > $this->maxCalendars) {
            throw RangeTooLarge::because('compare', count($calendars), $this->maxCalendars);
        }

        $start = CivilDate::from($from, $manager->context()->timezone);
        $end = CivilDate::from($to, $manager->context()->timezone);
        $perCalendar = [];

        foreach ($calendars as $id => $calendar) {
            $perCalendar[$id] = $manager->views()->days($calendar, $start, $end, $viewer);
        }

        $out = [];
        $span = count(new CalendarDateRange($start, $end));

        for ($i = 0; $i < $span; $i++) {
            $out[] = new ComparisonData($start->addDays($i)->toString(), array_map(static fn (array $days) => $days[$i], $perCalendar));
        }

        return $out;
    }

    /** @return list<CycleData> */
    public function cycles(string $calendarId, ?CalendarContext $context = null): array
    {
        return $this->cycleData($this->calendar($calendarId, $context));
    }

    /** @return list<CalendarPeriod> */
    public function periods(string $calendarId, CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, ?string $unit = null, ?CalendarContext $context = null): array
    {
        $calendar = $this->calendar($calendarId, $context);
        $this->guardRange($calendar->civil($from), $calendar->civil($to));

        return $calendar->periodsBetween($from, $to, $unit);
    }

    /**
     * Occurrences of the calendar's observances over a range, in date order.
     *
     * @return list<OccurrenceData>
     */
    public function observances(string $calendarId, CivilDate|string|DateTimeInterface $from, CivilDate|string|DateTimeInterface $to, bool $includeCancelled = true, ?CalendarContext $context = null, ?Authenticatable $viewer = null): array
    {
        $calendar = $this->calendar($calendarId, $context);
        $this->guardRange($calendar->civil($from), $calendar->civil($to));
        $out = [];

        foreach ($calendar->observanceOccurrencesBetween($from, $to, $viewer) as $occurrences) {
            foreach ($occurrences as $occurrence) {
                if ($includeCancelled || ! $occurrence->isCancelled()) {
                    $out[] = $this->manager($context)->views()->occurrence($calendar, $occurrence);
                }
            }
        }

        return $out;
    }

    /**
     * Search observance definitions by name (original, alternates or the
     * rendered label, accent- and case-insensitive), category and calendar.
     * With a window, each result carries its occurrences in that window and
     * observances without any are left out.
     *
     * @param  list<string>|null  $calendarIds
     * @param  list<string>|null  $categories
     * @return list<ObservanceData>
     */
    public function searchEvents(
        ?string $query = null,
        ?array $calendarIds = null,
        ?array $categories = null,
        CivilDate|string|DateTimeInterface|null $from = null,
        CivilDate|string|DateTimeInterface|null $to = null,
        int $limit = 50,
        ?CalendarContext $context = null,
        ?Authenticatable $viewer = null,
    ): array {
        $limit = max(1, min($limit, 500));
        $manager = $this->manager($context);
        $calendars = $calendarIds === null ? $manager->calendars() : array_map(static fn (string $id): Calendar => $manager->calendar($id), $calendarIds);
        $needle = $query === null || trim($query) === '' ? null : Term::foldFor(trim($query));
        $results = [];

        foreach ($calendars as $calendar) {
            $occurrencesByObservance = [];

            if ($from !== null && $to !== null) {
                $this->guardRange($calendar->civil($from), $calendar->civil($to));

                foreach ($calendar->observanceOccurrencesBetween($from, $to, $viewer) as $occurrences) {
                    foreach ($occurrences as $occurrence) {
                        $occurrencesByObservance[$occurrence->observance->id][] = $manager->views()->occurrence($calendar, $occurrence);
                    }
                }
            }

            foreach ($calendar->observances($viewer) as $observance) {
                $name = $manager->views()->observanceName($calendar, $observance);

                if ($categories !== null && ! in_array($observance->category, $categories, true)) {
                    continue;
                }

                if ($needle !== null && ! $this->matches($observance, $name, $needle)) {
                    continue;
                }

                if ($from !== null && $to !== null && ! isset($occurrencesByObservance[$observance->id])) {
                    continue;
                }

                $results[] = new ObservanceData(
                    $observance,
                    $name,
                    $observance->description === null ? null : $manager->views()->observanceName($calendar, $observance, 'description'),
                    $occurrencesByObservance[$observance->id] ?? [],
                );

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    public function history(string $calendarId, ?CalendarContext $context = null, Authenticatable|SystemActor|null $viewer = null): HistoryData
    {
        $calendar = $this->calendar($calendarId, $context, includeInactive: true);
        $tenant = $calendar->definition()->tenant();
        $actors = $this->authorizer->allows(CalendarAuthorizer::VIEW_AUDIT, $viewer ?? $calendar->defaultViewer(), $calendar->context()->tenant, $calendarId);

        $reviewer = $this->mayReview($viewer ?? $calendar->defaultViewer(), $calendar);

        $versions = array_values(array_map(static function (VersionSummary $v) use ($actors): array {
            $data = $v->toArray();

            if (! $actors) {
                // Who did what, and what reviewers wrote, is audit data.
                $data['submitted_by'] = $data['reviewed_by'] = $data['published_by'] = $data['review_notes'] = null;
            }

            return $data;
        }, array_filter(
            $this->repository->isAvailable() ? $this->repository->versions($tenant, $calendarId) : [],
            // Versions still in the workflow are listed only for those who may review them.
            static fn (VersionSummary $v): bool => $reviewer || $v->status->isReleased(),
        )));

        if ($versions === []) {
            // Code-registered calendar: its versions live in the registry.
            $versions = [[
                'calendar' => $calendarId,
                'version' => $calendar->version(),
                'status' => $calendar->definition()->version->status->value,
                'content_hash' => $calendar->definition()->contentHash(),
                'published_at' => null,
                'source' => 'code',
            ]];
        }

        return new HistoryData(
            calendar: $calendarId,
            status: $calendar->status()->value,
            versions: $versions,
            revisions: array_map(static fn (RevisionEntry $e): array => [
                'action' => $e->action,
                'actor' => $actors ? $e->actor : null,
                'changes' => $e->changes,
                'at' => $e->at->format(DATE_ATOM),
            ], $this->audit->revisions($tenant, 'calendar', $calendarId)),
            verifications: array_map(static fn (Verification $v): array => [
                'subject' => $v->subjectKey,
                'status' => $v->status->value,
                'actor' => $actors ? $v->actor : null,
                'notes' => $v->notes,
                'at' => $v->at->format(DATE_ATOM),
            ], $this->audit->verifications($tenant, 'calendar', $calendarId)),
            actorsVisible: $actors,
        );
    }

    public function translations(string $calendarId, ?CalendarContext $context = null): TranslationsData
    {
        $calendar = $this->calendar($calendarId, $context);
        $definition = $calendar->definition();
        $ctx = $calendar->context();
        $registered = [];

        if ($this->translations !== null) {
            foreach ($ctx->isGlobal() ? [TenantKey::GLOBAL] : [TenantKey::GLOBAL, $ctx->tenant] as $tenant) {
                foreach ($this->translations->map($tenant, $calendarId) as $key => $locales) {
                    // Tenant entries override global ones key by key (later scope wins here).
                    $registered[$key] = [...($registered[$key] ?? []), ...$locales];
                }
            }
        }

        $terms = [];
        $add = static function (string $key, Term $term) use (&$terms, $registered): void {
            $terms[] = [
                'key' => $key,
                'original' => $term->original,
                'language' => $term->language,
                'alternates' => $term->alternates,
                'version' => $term->translations,
                'registered' => $registered[$key] ?? [],
            ];
        };

        $add(Localizer::calendarKey($calendarId), $definition->name);

        foreach ($definition->cycles as $cycle) {
            $add(Localizer::cycleKey($calendarId, $cycle->id), $cycle->name);

            foreach ($cycle->positions as $position) {
                $add(Localizer::positionKey($calendarId, $cycle->id, $position->id), $position->label);
            }
        }

        foreach ($definition->names as $unit => $names) {
            foreach ($names as $name) {
                $add(Localizer::nameKey($calendarId, $unit, $name->id), $name->label);
            }
        }

        foreach ($calendar->observances() as $observance) {
            $add(Localizer::observanceKey($calendarId, $observance->id), $observance->name);

            if ($observance->description !== null) {
                $add(Localizer::observanceKey($calendarId, $observance->id, 'description'), $observance->description);
            }
        }

        return new TranslationsData($calendarId, $calendar->version(), $terms);
    }

    /**
     * Installation diagnostics. They expose class names and configuration,
     * so they need nweh-mundani.view-diagnostics (or a SystemActor).
     *
     * @return list<DiagnosticCheck>
     */
    public function diagnostics(Authenticatable|SystemActor|null $viewer, ?CalendarContext $context = null): array
    {
        $context = $this->manager($context)->context();
        $this->authorizer->authorize(CalendarAuthorizer::VIEW_DIAGNOSTICS, $viewer, $context->tenant, 'diagnostics');

        return $this->diagnostics->run($context);
    }

    private function mayReview(Authenticatable|SystemActor|null $viewer, Calendar $calendar): bool
    {
        $tenant = $calendar->context()->tenant;

        return $this->authorizer->allows(CalendarAuthorizer::MANAGE_CALENDARS, $viewer, $tenant, $calendar->id())
            || $this->authorizer->allows(CalendarAuthorizer::REVIEW_CALENDARS, $viewer, $tenant, $calendar->id());
    }

    private function details(Calendar $calendar, ?Authenticatable $viewer): CalendarDetailsData
    {
        $viewer ??= $calendar->defaultViewer();
        $definition = $calendar->definition();
        $context = $calendar->context();

        $ids = $definition->metadata->sources;

        foreach ($definition->cycles as $cycle) {
            foreach ([...$cycle->anchors, ...$cycle->exceptions] as $item) {
                array_push($ids, ...$item->sources);
            }
        }

        $visible = [];
        $hidden = 0;
        $missing = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $source = $this->sources->find($context, $id);

            if ($source === null) {
                $missing[] = $id;
            } elseif ($this->gate->allowsSource($source, $context, $viewer)) {
                $visible[] = new SourceData($source);
            } else {
                $hidden++;
            }
        }

        $description = $definition->metadata->description;

        return new CalendarDetailsData(
            summary: $calendar->summary(),
            description: $description === null ? null : $calendar->localizer()->label($description, "calendar.{$calendar->id()}.description", $context->locale, $context),
            epoch: $definition->epoch?->date->toString(),
            cycles: $this->cycleData($calendar),
            sources: $visible,
            hiddenSources: $hidden,
            missingSources: $missing,
            extra: $definition->metadata->extra,
            publishedAt: $definition->version->publishedAt?->format(DATE_ATOM),
            validation: $calendar->validate(),
        );
    }

    /** @return list<CycleData> */
    private function cycleData(Calendar $calendar): array
    {
        $id = $calendar->id();
        $context = $calendar->context();
        $localizer = $calendar->localizer();

        return array_map(static fn ($cycle): CycleData => new CycleData(
            id: $cycle->id,
            name: $localizer->label($cycle->name, Localizer::cycleKey($id, $cycle->id), $context->locale, $context),
            original: $cycle->name->original,
            length: $cycle->length(),
            continuity: $cycle->continuity->value,
            effectiveFrom: $cycle->effective->from?->toString(),
            effectiveUntil: $cycle->effective->until?->toString(),
            positions: array_map(static fn (CyclePosition $p): array => [
                'id' => $p->id,
                'ordinal' => $p->ordinal,
                'label' => $localizer->label($p->label, Localizer::positionKey($id, $cycle->id, $p->id), $context->locale, $context),
                'original' => $p->label->original,
                'term' => $p->label->toArray(),
            ], $cycle->positions),
            anchors: array_map(static fn (CalendarAnchor $a): array => $a->toArray(), $cycle->anchors),
            exceptions: array_map(static fn (CalendarException $e): array => $e->toArray(), $cycle->exceptions),
        ), $calendar->definition()->cycles);
    }

    private function matches(Observance $observance, string $label, string $needle): bool
    {
        foreach ([$observance->name->original, ...$observance->name->alternates, $label] as $candidate) {
            if (str_contains(Term::foldFor($candidate), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function calendar(string $id, ?CalendarContext $context, ?string $version = null, bool $includeInactive = false): Calendar
    {
        return $this->manager($context)->calendar($id, $version, $includeInactive);
    }

    private function manager(?CalendarContext $context): CalendarManager
    {
        return $context === null ? $this->manager : $this->manager->withContext($context);
    }

    private function guardRange(CivilDate $from, CivilDate $to): void
    {
        $days = count(new CalendarDateRange($from, $to));

        if ($days > $this->maxRangeDays) {
            throw RangeTooLarge::days($days, $this->maxRangeDays);
        }
    }
}
