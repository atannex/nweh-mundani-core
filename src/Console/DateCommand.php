<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Dates\CycleReading;
use Atannex\NwehMundani\Core\Exceptions\NwehMundaniException;
use Atannex\NwehMundani\Core\Observances\Observance;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class DateCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:date {calendar : Calendar identifier} {date? : Civil date YYYY-MM-DD (default: today in the configured timezone)} {--tenant=} {--locale=} {--json : Output JSON}';

    protected $description = 'Show how a calendar represents a civil date';

    public function handle(): int
    {
        $manager = $this->manager();

        try {
            $calendar = $manager->calendar($this->stringArgument('calendar'));
            $date = $this->argument('date');
            $result = $calendar->convert(is_string($date) ? $date : new DateTimeImmutable('now'));
            $calendarDate = $result->date();
            $observances = $calendar->observancesOn($calendarDate->civil);
        } catch (NwehMundaniException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $locale = $manager->context()->locale;

        if ($this->option('json')) {
            $this->json([
                ...$calendarDate->toArray($locale),
                'status' => $result->status->value,
                'notes' => $result->notes,
                'observances' => array_map(static fn (Observance $o): array => ['id' => $o->id, 'name' => $o->name->label($locale), 'category' => $o->category], $observances),
            ]);

            return self::SUCCESS;
        }

        $this->components->info("{$calendar->name()} on {$calendarDate->civil}");

        if ($calendarDate->components !== []) {
            $native = CompareCommand::nativeDate($calendar->day($calendarDate->civil));
            $this->components->twoColumnDetail('Date in this calendar', (string) $native);

            foreach ($calendarDate->components as $name => $value) {
                $this->components->twoColumnDetail($name, (string) $value);
            }
        }

        if ($calendarDate->cycles !== []) {
            $this->table(['Cycle', 'Position', 'Label', 'Original', 'Run', 'Certainty', 'Anchor/override'], array_map(static fn (CycleReading $r): array => [
                $r->cycleId,
                "{$r->ordinal()}/{$r->length} ({$r->positionId()})",
                $r->label($locale),
                $r->original(),
                $r->sequence,
                $r->certainty->value,
                $r->exceptionId ?? $r->anchorId ?? '—',
            ], array_values($calendarDate->cycles)));
        }

        foreach ($result->notes as $note) {
            $this->components->warn($note);
        }

        foreach ($observances as $observance) {
            $this->components->twoColumnDetail("Observance: {$observance->name->label($locale)}", "{$observance->category} [{$observance->verification->value}]");
        }

        return self::SUCCESS;
    }
}
