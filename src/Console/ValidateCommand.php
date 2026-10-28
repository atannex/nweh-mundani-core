<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Exceptions\NwehMundaniException;
use Illuminate\Console\Command;

final class ValidateCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:validate {calendar? : Calendar identifier (default: all)} {--tenant=} {--strict : Treat warnings as failures}';

    protected $description = 'Validate calendar definitions';

    public function handle(): int
    {
        $manager = $this->manager();
        $id = $this->argument('calendar');

        try {
            $calendars = is_string($id) ? [$manager->calendar($id)] : array_values($manager->calendars());
        } catch (NwehMundaniException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($calendars as $calendar) {
            $report = $calendar->validate();
            $strictFail = $this->option('strict') && $report->warnings !== [];

            if ($report->passes() && ! $strictFail) {
                $this->components->twoColumnDetail("{$calendar->id()} v{$calendar->version()}", '<fg=green>valid</>'.($report->warnings === [] ? '' : ' ('.count($report->warnings).' warning(s))'));
            } else {
                $failed = true;
                $this->components->twoColumnDetail("{$calendar->id()} v{$calendar->version()}", '<fg=red>invalid</>');
            }

            foreach ($report->errors as $error) {
                $this->components->bulletList(["<fg=red>error</> {$error}"]);
            }

            foreach ($report->warnings as $warning) {
                $this->components->bulletList(["<fg=yellow>warning</> {$warning}"]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
