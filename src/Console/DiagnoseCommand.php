<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Kernel\CalendarDiagnostics;
use Atannex\NwehMundani\Core\Kernel\DiagnosticStatus;
use Illuminate\Console\Command;

final class DiagnoseCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:diagnose {--tenant=}';

    protected $description = 'Inspect the installation: runtime, drivers, storage, cache and every calendar definition';

    public function handle(CalendarDiagnostics $diagnostics): int
    {
        $failed = false;

        foreach ($diagnostics->run($this->manager()->context()) as $check) {
            $label = match ($check->status) {
                DiagnosticStatus::Ok => '<fg=green>ok</>',
                DiagnosticStatus::Warning => '<fg=yellow>warning</>',
                DiagnosticStatus::Failure => '<fg=red>failure</>',
            };

            $failed = $failed || $check->status === DiagnosticStatus::Failure;
            $this->components->twoColumnDetail("{$check->name} {$label}", $check->message);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
