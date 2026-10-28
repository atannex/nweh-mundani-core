<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Illuminate\Console\Command;

/**
 * Shared --tenant / --locale handling for the commands.
 *
 * @mixin Command
 */
trait ResolvesContext
{
    protected function manager(): CalendarManager
    {
        /** @var CalendarManager $manager */
        $manager = $this->laravel->make(CalendarManager::class);

        $tenant = $this->hasOption('tenant') ? $this->option('tenant') : null;
        $locale = $this->hasOption('locale') ? $this->option('locale') : null;

        if (is_string($tenant)) {
            $manager = $manager->forTenant($tenant);
        }

        if (is_string($locale)) {
            $manager = $manager->withLocale($locale);
        }

        return $manager;
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    /** @param array<mixed> $data */
    protected function json(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
