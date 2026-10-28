<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Application\CalendarViewBuilder;
use Atannex\NwehMundani\Core\Dates\CalendarDateComparison;
use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\CalendarUnavailable;
use Atannex\NwehMundani\Core\Localization\LocalizationProvider;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Observances\ObservanceResolver;
use Atannex\NwehMundani\Core\Observances\ObservanceService;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use Atannex\NwehMundani\Core\Tenancy\TenantResolver;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The package's front door (behind the NwehMundani facade).
 *
 * The manager itself holds no per-request state: unless a context is set
 * explicitly with forTenant()/withContext(), each call reads the tenant from
 * the TenantResolver at that moment. forTenant() and friends return copies.
 */
final class CalendarManager
{
    /** @param Closure(): ?Authenticatable $viewer */
    public function __construct(
        private readonly CalendarResolver $resolver,
        private readonly CalendarRegistry $registry,
        private readonly CalendarFactory $factory,
        private readonly CalendarValidator $validator,
        private readonly DriverRegistry $drivers,
        private readonly RuleRegistry $rules,
        private readonly ObservanceService $observances,
        private readonly Localizer $localizer,
        private readonly TenantResolver $tenants,
        private readonly Closure $viewer,
        private readonly ?string $defaultLocale = null,
        private readonly string $defaultTimezone = 'UTC',
        private readonly ?CalendarContext $context = null,
        private readonly CalendarViewBuilder $views = new CalendarViewBuilder,
    ) {}

    /** The context calls run in right now. */
    public function context(): CalendarContext
    {
        return $this->context ?? new CalendarContext($this->tenants->current(), $this->defaultLocale, $this->defaultTimezone);
    }

    public function withContext(CalendarContext $context): self
    {
        return new self($this->resolver, $this->registry, $this->factory, $this->validator, $this->drivers, $this->rules, $this->observances, $this->localizer, $this->tenants, $this->viewer, $this->defaultLocale, $this->defaultTimezone, $context, $this->views);
    }

    public function forTenant(string|int|null $tenant): self
    {
        return $this->withContext($this->context()->withTenant($tenant));
    }

    public function withLocale(?string $locale): self
    {
        return $this->withContext($this->context()->withLocale($locale));
    }

    public function withTimezone(string $timezone): self
    {
        return $this->withContext($this->context()->withTimezone($timezone));
    }

    /**
     * @param  bool  $includeInactive  Also resolve suspended or archived stored calendars (for history pages).
     *
     * @throws CalendarNotFound|CalendarUnavailable
     */
    public function calendar(string $id, ?string $version = null, bool $includeInactive = false): Calendar
    {
        $context = $this->context();
        $definition = $this->resolver->resolve($context, $id, $version, $includeInactive);
        $status = $includeInactive ? ($this->resolver->statusOf($context, $id) ?? CalendarStatus::Active) : CalendarStatus::Active;

        return $this->bind($definition, $context, $status);
    }

    public function has(string $id): bool
    {
        return $this->resolver->exists($this->context(), $id);
    }

    /**
     * Every calendar visible in the current context, keyed and sorted by id.
     *
     * @return array<string, Calendar>
     */
    public function calendars(bool $includeInactive = false): array
    {
        $context = $this->context();

        return array_map(fn (CalendarDefinition $d): Calendar => $this->bind($d, $context, $includeInactive ? ($this->resolver->statusOf($context, $d->id()) ?? CalendarStatus::Active) : CalendarStatus::Active), $this->resolver->all($context, $includeInactive));
    }

    /**
     * One civil date in several calendars (all visible ones when $ids is null).
     *
     * @param  list<string>|null  $ids
     *
     * @throws CalendarNotFound when an explicitly named calendar does not exist.
     */
    public function compare(CivilDate|string|DateTimeInterface $date, ?array $ids = null): CalendarDateComparison
    {
        $context = $this->context();
        $civil = CivilDate::from($date, $context->timezone);
        $calendars = $ids === null ? $this->calendars() : array_combine($ids, array_map(fn (string $id): Calendar => $this->calendar($id), $ids));

        return new CalendarDateComparison($civil, array_map(static fn (Calendar $c) => $c->convert($civil), $calendars));
    }

    /**
     * Register a published definition from code (global unless it names a tenant).
     *
     * @param  CalendarDefinition|array<string, mixed>  $definition
     */
    public function register(CalendarDefinition|array $definition): CalendarDefinition
    {
        return $this->registry->register(is_array($definition) ? $this->factory->fromArray($definition) : $definition);
    }

    /** @param CalendarDriver|class-string<CalendarDriver> $driver */
    public function extend(CalendarDriver|string $driver, bool $replace = false): CalendarDriver
    {
        return $this->drivers->register($driver, $replace);
    }

    /** @param RuleHandler|class-string<RuleHandler> $handler */
    public function extendRules(RuleHandler|string $handler, bool $replace = false): void
    {
        $this->rules->register($handler, $replace);
    }

    public function addObservanceResolver(ObservanceResolver $resolver): void
    {
        $this->observances->addResolver($resolver);
    }

    public function addLocalizationProvider(LocalizationProvider $provider): void
    {
        $this->localizer->addProvider($provider);
    }

    public function drivers(): DriverRegistry
    {
        return $this->drivers;
    }

    public function rules(): RuleRegistry
    {
        return $this->rules;
    }

    public function factory(): CalendarFactory
    {
        return $this->factory;
    }

    public function validator(): CalendarValidator
    {
        return $this->validator;
    }

    public function views(): CalendarViewBuilder
    {
        return $this->views;
    }

    public function resolver(): CalendarResolver
    {
        return $this->resolver;
    }

    public function localizer(): Localizer
    {
        return $this->localizer;
    }

    public function observanceService(): ObservanceService
    {
        return $this->observances;
    }

    private function bind(CalendarDefinition $definition, CalendarContext $context, CalendarStatus $status = CalendarStatus::Active): Calendar
    {
        return new Calendar($definition, $this->drivers->get($definition->driver), $context, $this->validator, $this->observances, $this->localizer, $this->viewer, $this->views, $status);
    }
}
