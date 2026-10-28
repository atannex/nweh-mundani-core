<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Domain\CalendarCycle;
use Atannex\NwehMundani\Core\Kernel\Calendar;
use Illuminate\Console\Command;

final class ListCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:list {--tenant= : Tenant key (default: the current tenant or global)} {--json : Output JSON}';

    protected $description = 'List the registered calendars and their active versions';

    public function handle(): int
    {
        $calendars = $this->manager()->calendars();

        $rows = array_values(array_map(static fn (Calendar $c): array => [
            'id' => $c->id(),
            'name' => $c->definition()->name->original,
            'version' => $c->version(),
            'driver' => $c->definition()->driver,
            'scope' => $c->definition()->tenant() === '' ? 'global' : $c->definition()->tenant(),
            'cycles' => implode(', ', array_map(static fn (CalendarCycle $cy): string => "{$cy->id} ({$cy->length()})", $c->definition()->cycles)),
            'verification' => $c->definition()->metadata->verification->value,
        ], $calendars));

        if ($this->option('json')) {
            $this->json($rows);

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn('No calendars are registered.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Version', 'Driver', 'Scope', 'Cycles', 'Verification'], $rows);

        return self::SUCCESS;
    }
}
