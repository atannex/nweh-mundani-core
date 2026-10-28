<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Domain\CalendarCapabilities;
use Atannex\NwehMundani\Core\Drivers\DriverRegistry;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\ObservanceRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\EloquentCalendarRepository;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use Atannex\NwehMundani\Core\Tenancy\TenantResolver;
use Throwable;

/**
 * Inspects the installation: runtime, drivers, rule handlers, every
 * visible calendar definition, storage and cache.
 */
final readonly class CalendarDiagnostics
{
    public function __construct(
        private DriverRegistry $drivers,
        private RuleRegistry $rules,
        private CalendarResolver $resolver,
        private CalendarValidator $validator,
        private CalendarRepository $calendars,
        private ObservanceRepository $observances,
        private SourceRepository $sources,
        private DefinitionCache $cache,
        private TenantResolver $tenants,
        private bool $persistenceEnabled,
    ) {}

    /** @return list<DiagnosticCheck> */
    public function run(?CalendarContext $context = null): array
    {
        $checks = [];

        $checks[] = new DiagnosticCheck('php', DiagnosticStatus::Ok, 'PHP '.PHP_VERSION);
        $checks[] = extension_loaded('intl')
            ? new DiagnosticCheck('ext-intl', DiagnosticStatus::Ok, 'available: search keys use Unicode NFC')
            : new DiagnosticCheck('ext-intl', DiagnosticStatus::Warning, 'missing: search keys fall back to the exact spelling, so differently composed but equivalent spellings will not match');

        foreach ($this->drivers->all() as $key => $driver) {
            $capabilities = CalendarCapabilities::ofDriver($driver)->toArray();
            $checks[] = new DiagnosticCheck("driver:{$key}", DiagnosticStatus::Ok, "{$driver->kind()->value} v{$driver->version()} (".($capabilities === [] ? 'no capabilities' : implode(', ', $capabilities)).')');
        }

        $checks[] = new DiagnosticCheck('rule-handlers', DiagnosticStatus::Ok, implode(', ', $this->rules->types()));

        $tenant = $this->tenants::class;
        $checks[] = new DiagnosticCheck('tenancy', DiagnosticStatus::Ok, "resolver {$tenant}");

        if ($this->persistenceEnabled) {
            $ready = $this->calendars->isAvailable() && $this->observances->isAvailable() && $this->sources->isAvailable();
            $checks[] = $ready
                ? new DiagnosticCheck('persistence', DiagnosticStatus::Ok, $this->calendars instanceof EloquentCalendarRepository ? 'database tables present' : 'custom repository available')
                : new DiagnosticCheck('persistence', DiagnosticStatus::Failure, 'enabled but the nmc_* tables are missing: run `php artisan migrate`, or set NWEH_MUNDANI_PERSISTENCE=false');
        } else {
            $checks[] = new DiagnosticCheck('persistence', DiagnosticStatus::Ok, 'disabled: code-registered definitions and in-memory storage only');
        }

        $checks[] = new DiagnosticCheck('cache', DiagnosticStatus::Ok, $this->cache->enabled() ? 'enabled for stored definitions and observances' : 'disabled');

        try {
            $definitions = $this->resolver->all($context ?? new CalendarContext($this->tenants->current()));
        } catch (Throwable $e) {
            return [...$checks, new DiagnosticCheck('calendars', DiagnosticStatus::Failure, 'could not load definitions: '.$e->getMessage())];
        }

        if ($definitions === []) {
            $checks[] = new DiagnosticCheck('calendars', DiagnosticStatus::Warning, 'no calendars are registered');
        }

        foreach ($definitions as $id => $definition) {
            $report = $this->validator->validate($definition);
            $status = match (true) {
                ! $report->passes() => DiagnosticStatus::Failure,
                $report->warnings !== [] => DiagnosticStatus::Warning,
                default => DiagnosticStatus::Ok,
            };
            $detail = $report->passes()
                ? "v{$definition->version->number} valid".($report->warnings === [] ? '' : ', '.count($report->warnings).' warning(s): '.implode('; ', array_map('strval', $report->warnings)))
                : implode('; ', array_map('strval', $report->errors));

            $checks[] = new DiagnosticCheck("calendar:{$id}", $status, $detail);
        }

        return $checks;
    }
}
