<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core;

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Application\CalendarViewBuilder;
use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;
use Atannex\NwehMundani\Core\Application\TranslationManagementService;
use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Console\CompareCommand;
use Atannex\NwehMundani\Core\Console\DateCommand;
use Atannex\NwehMundani\Core\Console\DiagnoseCommand;
use Atannex\NwehMundani\Core\Console\ListCommand;
use Atannex\NwehMundani\Core\Console\SeedExamplesCommand;
use Atannex\NwehMundani\Core\Console\ShowCommand;
use Atannex\NwehMundani\Core\Console\ValidateCommand;
use Atannex\NwehMundani\Core\Contracts\CalendarDefinitionProvider;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\HebrewDriver;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\SolarDriver;
use Atannex\NwehMundani\Core\Drivers\Arithmetic\TabularLunarDriver;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Drivers\Cyclic\CyclicDriver;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;
use Atannex\NwehMundani\Core\Examples\LebialemCalendars;
use Atannex\NwehMundani\Core\Examples\ReferenceCalendars;
use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;
use Atannex\NwehMundani\Core\Governance\CalendarAdministration;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\GateCalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Governance\SourceCatalog;
use Atannex\NwehMundani\Core\Kernel\CalendarDiagnostics;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\CalendarRegistry;
use Atannex\NwehMundani\Core\Kernel\CalendarResolver;
use Atannex\NwehMundani\Core\Kernel\CalendarValidator;
use Atannex\NwehMundani\Core\Localization\LocalizationProvider;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Localization\TranslationCatalog;
use Atannex\NwehMundani\Core\Localization\TranslationMemo;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Observances\ObservanceGate;
use Atannex\NwehMundani\Core\Observances\ObservanceResolver;
use Atannex\NwehMundani\Core\Observances\ObservanceService;
use Atannex\NwehMundani\Core\Observances\RepositoryObservanceResolver;
use Atannex\NwehMundani\Core\Observances\StaticObservanceResolver;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\TranslationRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Atannex\NwehMundani\Core\Persistence\Eloquent\DatabaseUnitOfWork;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentAuditTrail;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentCalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentSourceRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentTranslationRepository;
use Atannex\NwehMundani\Core\Persistence\InMemory\ImmediateUnitOfWork;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemoryAuditTrail;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemoryCalendarRepository;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemoryObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemorySourceRepository;
use Atannex\NwehMundani\Core\Persistence\InMemory\InMemoryTranslationRepository;
use Atannex\NwehMundani\Core\Rules\Handlers\CyclePositionHandler;
use Atannex\NwehMundani\Core\Rules\Handlers\FixedDateHandler;
use Atannex\NwehMundani\Core\Rules\Handlers\OccurrencesHandler;
use Atannex\NwehMundani\Core\Rules\Handlers\RecurrenceHandler;
use Atannex\NwehMundani\Core\Rules\Handlers\RelativeHandler;
use Atannex\NwehMundani\Core\Rules\RuleHandler;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use Atannex\NwehMundani\Core\Tenancy\NullTenantResolver;
use Atannex\NwehMundani\Core\Tenancy\TenantResolver;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class NwehMundaniServiceProvider extends ServiceProvider
{
    public const string TAG_DRIVERS = 'nweh-mundani.drivers';

    public const string TAG_RULE_HANDLERS = 'nweh-mundani.rule-handlers';

    public const string TAG_OBSERVANCE_RESOLVERS = 'nweh-mundani.observance-resolvers';

    public const string TAG_LOCALIZATION_PROVIDERS = 'nweh-mundani.localization-providers';

    public const string TAG_PROVIDERS = 'nweh-mundani.providers';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nweh-mundani.php', 'nweh-mundani');

        $this->app->singleton(DriverRegistry::class, function (Container $app): DriverRegistry {
            $registry = new DriverRegistry($app);
            $registry->register(CyclicDriver::class);
            $registry->register(SolarDriver::class);
            $registry->register(TabularLunarDriver::class);
            $registry->register(HebrewDriver::class);

            foreach ([...$this->classes('drivers'), ...$this->tagged(self::TAG_DRIVERS)] as $driver) {
                $registry->register($this->trusted($driver, CalendarDriver::class));
            }

            return $registry;
        });

        $this->app->singleton(RuleRegistry::class, function (Container $app): RuleRegistry {
            $registry = new RuleRegistry($app);

            foreach ([FixedDateHandler::class, OccurrencesHandler::class, CyclePositionHandler::class, RecurrenceHandler::class, RelativeHandler::class] as $handler) {
                $registry->register($handler);
            }

            foreach ([...$this->classes('rule_handlers'), ...$this->tagged(self::TAG_RULE_HANDLERS)] as $handler) {
                $registry->register($this->trusted($handler, RuleHandler::class));
            }

            return $registry;
        });

        // Not shared: the factory collects violations while it builds.
        $this->app->bind(CalendarFactory::class);
        $this->app->singleton(ObservanceFactory::class);
        $this->app->singleton(CalendarValidator::class);

        $this->app->scoped(TranslationMemo::class);
        $this->app->singleton(TranslationCatalog::class, static fn (Container $app): TranslationCatalog => new TranslationCatalog(
            $app->make(TranslationRepository::class),
            $app->make(DefinitionCache::class),
            static fn (): TranslationMemo => $app->make(TranslationMemo::class),
        ));

        $this->app->singleton(Localizer::class, function (Container $app): Localizer {
            $localizer = new Localizer;
            $localizer->addProvider($app->make(TranslationCatalog::class));

            foreach ([...$this->classes('localization_providers'), ...$this->tagged(self::TAG_LOCALIZATION_PROVIDERS)] as $provider) {
                $localizer->addProvider($this->trusted($provider, LocalizationProvider::class));
            }

            return $localizer;
        });

        $this->app->singleton(TenantResolver::class, function (Container $app): TenantResolver {
            $class = config('nweh-mundani.tenant_resolver');

            return is_string($class) ? $this->trusted($app->make($class), TenantResolver::class) : new NullTenantResolver;
        });

        $this->app->singleton(CalendarAuthorizer::class, function (Container $app): CalendarAuthorizer {
            $class = config('nweh-mundani.authorizer', GateCalendarAuthorizer::class);

            return $this->trusted($app->make(is_string($class) ? $class : GateCalendarAuthorizer::class), CalendarAuthorizer::class);
        });

        $this->app->singleton(DefinitionCache::class, function (Container $app): DefinitionCache {
            $store = null;

            if (config('nweh-mundani.cache.enabled') && $app->bound('cache')) {
                $name = config('nweh-mundani.cache.store');
                $store = $app->make('cache')->store(is_string($name) ? $name : null);
            }

            $prefix = config('nweh-mundani.cache.prefix', 'nmc');
            $ttl = config('nweh-mundani.cache.ttl', 3600);

            return new DefinitionCache($store, $app->make(CalendarFactory::class), $app->make(ObservanceFactory::class), is_string($prefix) ? $prefix : 'nmc', is_int($ttl) ? $ttl : 3600);
        });

        $this->registerPersistence();
        $this->registerStaticCatalog();

        $this->app->singleton(CalendarResolver::class, static fn (Container $app): CalendarResolver => new CalendarResolver(
            $app->make(CalendarRegistry::class),
            $app->make(CalendarRepository::class),
            $app->make(DefinitionCache::class),
            (bool) config('nweh-mundani.tenancy.allow_shadowing', false),
        ));

        $this->app->singleton(ObservanceGate::class, static fn (Container $app): ObservanceGate => new ObservanceGate($app->bound(Gate::class) ? $app->make(Gate::class) : null));

        $this->app->singleton(ObservanceService::class, function (Container $app): ObservanceService {
            $service = new ObservanceService($app->make(RuleRegistry::class), $app->make(ObservanceGate::class));

            // Stored observances first, so the database overrides code for the same identifier.
            $service->addResolver(new RepositoryObservanceResolver($app->make(ObservanceRepository::class), $app->make(DefinitionCache::class)));
            $service->addResolver(new StaticObservanceResolver($app->make(CalendarRegistry::class), $app->make('nweh-mundani.static-observances')));

            foreach ([...$this->classes('observance_resolvers'), ...$this->tagged(self::TAG_OBSERVANCE_RESOLVERS)] as $resolver) {
                $service->addResolver($this->trusted($resolver, ObservanceResolver::class));
            }

            return $service;
        });

        $this->app->singleton(SourceCatalog::class, static fn (Container $app): SourceCatalog => new SourceCatalog(
            $app->make(SourceRepository::class),
            $app->make('nweh-mundani.static-sources'),
            $app->make(CalendarRegistry::class),
        ));

        $this->app->singleton(CalendarViewBuilder::class, static fn (): CalendarViewBuilder => new CalendarViewBuilder(self::int('queries.max_range_days', 1830)));

        $this->app->singleton(CalendarWorkflowService::class, static fn (Container $app): CalendarWorkflowService => new CalendarWorkflowService(
            $app->make(CalendarManager::class),
            $app->make(CalendarRepository::class),
            $app->make(SourceRepository::class),
            $app->make(SourceCatalog::class),
            $app->make(AuditTrail::class),
            $app->make(UnitOfWork::class),
            $app->make(CalendarAuthorizer::class),
            $app->make(CalendarFactory::class),
            $app->make(CalendarValidator::class),
            $app->make(CalendarResolver::class),
            $app->make(DefinitionCache::class),
            $app->bound('events') ? $app->make(Dispatcher::class) : null,
            (bool) config('nweh-mundani.workflow.require_review', true),
            (bool) config('nweh-mundani.workflow.separate_approver', true),
            (bool) config('nweh-mundani.workflow.require_sources', false),
        ));

        $this->app->singleton(ObservanceManagementService::class, static fn (Container $app): ObservanceManagementService => new ObservanceManagementService(
            $app->make(CalendarManager::class),
            $app->make(ObservanceRepository::class),
            $app->make(ObservanceFactory::class),
            $app->make(RuleRegistry::class),
            $app->make(AuditTrail::class),
            $app->make(UnitOfWork::class),
            $app->make(CalendarAuthorizer::class),
            $app->make(DefinitionCache::class),
            $app->bound('events') ? $app->make(Dispatcher::class) : null,
        ));

        $this->app->singleton(TranslationManagementService::class, static fn (Container $app): TranslationManagementService => new TranslationManagementService(
            $app->make(CalendarManager::class),
            $app->make(TranslationRepository::class),
            $app->make(TranslationCatalog::class),
            $app->make(AuditTrail::class),
            $app->make(UnitOfWork::class),
            $app->make(CalendarAuthorizer::class),
            $app->bound('events') ? $app->make(Dispatcher::class) : null,
        ));

        $this->app->singleton(CalendarQueryService::class, static fn (Container $app): CalendarQueryService => new CalendarQueryService(
            $app->make(CalendarManager::class),
            $app->make(CalendarRepository::class),
            $app->make(AuditTrail::class),
            $app->make(SourceCatalog::class),
            $app->make(ObservanceGate::class),
            $app->make(CalendarAuthorizer::class),
            $app->make(CalendarDiagnostics::class),
            $app->make(TranslationCatalog::class),
            self::int('queries.max_range_days', 1830),
            self::int('queries.max_calendars', 50),
        ));

        $this->app->singleton(CalendarAdministration::class);

        $this->app->singleton(CalendarManager::class, static function (Container $app): CalendarManager {
            $locale = config('nweh-mundani.locale');
            $timezone = config('nweh-mundani.timezone', 'UTC');

            return new CalendarManager(
                $app->make(CalendarResolver::class),
                $app->make(CalendarRegistry::class),
                $app->make(CalendarFactory::class),
                $app->make(CalendarValidator::class),
                $app->make(DriverRegistry::class),
                $app->make(RuleRegistry::class),
                $app->make(ObservanceService::class),
                $app->make(Localizer::class),
                $app->make(TenantResolver::class),
                static function () use ($app): ?Authenticatable {
                    try {
                        return $app->bound('auth') ? $app->make('auth')->guard()->user() : null;
                    } catch (Throwable) {
                        return null;
                    }
                },
                is_string($locale) ? $locale : null,
                is_string($timezone) ? $timezone : 'UTC',
                null,
                $app->make(CalendarViewBuilder::class),
            );
        });

        $this->app->singleton(CalendarDiagnostics::class, static fn (Container $app): CalendarDiagnostics => new CalendarDiagnostics(
            $app->make(DriverRegistry::class),
            $app->make(RuleRegistry::class),
            $app->make(CalendarResolver::class),
            $app->make(CalendarValidator::class),
            $app->make(CalendarRepository::class),
            $app->make(ObservanceRepository::class),
            $app->make(SourceRepository::class),
            $app->make(DefinitionCache::class),
            $app->make(TenantResolver::class),
            (bool) config('nweh-mundani.persistence.enabled'),
        ));
    }

    public function boot(): void
    {
        if (config('nweh-mundani.persistence.enabled') && config('nweh-mundani.persistence.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/nweh-mundani.php' => config_path('nweh-mundani.php')], 'nweh-mundani-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'nweh-mundani-migrations');

            $this->commands([
                ListCommand::class,
                ShowCommand::class,
                DateCommand::class,
                CompareCommand::class,
                ValidateCommand::class,
                DiagnoseCommand::class,
                SeedExamplesCommand::class,
            ]);
        }
    }

    private function registerPersistence(): void
    {
        // Read when first resolved, not at registration, so config set later applies.
        $enabled = static fn (): bool => (bool) config('nweh-mundani.persistence.enabled');

        $this->app->singleton(CalendarRepository::class, static fn (Container $app): CalendarRepository => $enabled()
            ? new EloquentCalendarRepository($app->make(CalendarFactory::class))
            : new InMemoryCalendarRepository);

        $this->app->singleton(ObservanceRepository::class, static fn (Container $app): ObservanceRepository => $enabled()
            ? new EloquentObservanceRepository($app->make(ObservanceFactory::class))
            : new InMemoryObservanceRepository);

        $this->app->singleton(SourceRepository::class, static fn (): SourceRepository => $enabled() ? new EloquentSourceRepository : new InMemorySourceRepository);

        $this->app->singleton(TranslationRepository::class, static fn (): TranslationRepository => $enabled() ? new EloquentTranslationRepository : new InMemoryTranslationRepository);

        $this->app->singleton(AuditTrail::class, static fn (): AuditTrail => $enabled() ? new EloquentAuditTrail : new InMemoryAuditTrail);

        $this->app->singleton(UnitOfWork::class, static function (Container $app) use ($enabled): UnitOfWork {
            $connection = config('nweh-mundani.persistence.connection');

            return $enabled() ? new DatabaseUnitOfWork($app->make('db'), is_string($connection) ? $connection : null) : new ImmediateUnitOfWork;
        });
    }

    /**
     * Code-registered calendars, observances and sources: config, the
     * bundled examples and definition providers, loaded on first use.
     */
    private function registerStaticCatalog(): void
    {
        $this->app->singleton('nweh-mundani.static-observances', static fn (): InMemoryObservanceRepository => new InMemoryObservanceRepository);
        $this->app->singleton('nweh-mundani.static-sources', static fn (): InMemorySourceRepository => new InMemorySourceRepository);

        $this->app->singleton(CalendarRegistry::class, function (Container $app): CalendarRegistry {
            $pins = config('nweh-mundani.versions', []);
            $registry = new CalendarRegistry($app->make(CalendarValidator::class), is_array($pins) ? array_filter($pins, 'is_string') : []);

            $registry->defer(function (CalendarRegistry $registry) use ($app): void {
                /** @var CalendarFactory $factory */
                $factory = $app->make(CalendarFactory::class);
                /** @var ObservanceFactory $observanceFactory */
                $observanceFactory = $app->make(ObservanceFactory::class);
                /** @var InMemoryObservanceRepository $observances */
                $observances = $app->make('nweh-mundani.static-observances');
                /** @var InMemorySourceRepository $sources */
                $sources = $app->make('nweh-mundani.static-sources');

                $providers = [...$this->classes('providers'), ...$this->tagged(self::TAG_PROVIDERS)];

                if (config('nweh-mundani.examples.register')) {
                    array_unshift($providers, LebialemCalendars::class);
                }

                if (config('nweh-mundani.examples.reference')) {
                    array_unshift($providers, ReferenceCalendars::class);
                }

                $sets = [[
                    'calendars' => config('nweh-mundani.calendars', []),
                    'observances' => config('nweh-mundani.observances', []),
                    'sources' => config('nweh-mundani.sources', []),
                ]];

                foreach ($providers as $provider) {
                    $provider = $this->trusted($provider, CalendarDefinitionProvider::class);
                    $sets[] = ['calendars' => $provider->calendars(), 'observances' => $provider->observances(), 'sources' => $provider->sources()];
                }

                foreach ($sets as $set) {
                    foreach (is_iterable($set['sources']) ? $set['sources'] : [] as $source) {
                        $sources->save($source instanceof Source ? $source : Source::fromArray((array) $source));
                    }

                    foreach (is_iterable($set['calendars']) ? $set['calendars'] : [] as $definition) {
                        $registry->register($definition instanceof CalendarDefinition ? $definition : $factory->fromArray((array) $definition));
                    }

                    foreach (is_iterable($set['observances']) ? $set['observances'] : [] as $observance) {
                        $observances->save($observance instanceof Observance ? $observance : $observanceFactory->fromArray((array) $observance));
                    }
                }
            });

            return $registry;
        });
    }

    private static function int(string $key, int $default): int
    {
        $value = config("nweh-mundani.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    private function classes(string $key): array
    {
        $value = config("nweh-mundani.{$key}", []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return list<object> */
    private function tagged(string $tag): array
    {
        return iterator_to_array($this->app->tagged($tag), false);
    }

    /**
     * Resolve and type-check an extension coming from config or tags.
     *
     * @template T of object
     *
     * @param  class-string<T>  $interface
     * @return T
     */
    private function trusted(object|string $extension, string $interface): object
    {
        if (is_string($extension)) {
            if (! class_exists($extension)) {
                throw UnsafeRegistration::because("[{$extension}] is not a class.");
            }

            $extension = $this->app->make($extension);
        }

        if (! $extension instanceof $interface) {
            throw UnsafeRegistration::because($extension::class." does not implement {$interface}.");
        }

        return $extension;
    }
}
