<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Exceptions\NwehMundaniException;
use Atannex\NwehMundani\Core\Governance\SourceCatalog;
use Illuminate\Console\Command;

final class ShowCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:show {calendar : Calendar identifier} {--calendar-version= : A specific version} {--tenant=} {--json : Output JSON}';

    protected $description = "Show a calendar's metadata, capabilities, driver, cycles, anchors and sources";

    public function handle(SourceCatalog $sources): int
    {
        try {
            $version = $this->option('calendar-version');
            $calendar = $this->manager()->calendar($this->stringArgument('calendar'), is_string($version) ? $version : null);
        } catch (NwehMundaniException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $definition = $calendar->definition();
        $report = $calendar->validate();

        if ($this->option('json')) {
            $this->json([
                ...$definition->toArray(),
                'capabilities' => $calendar->capabilities()->toArray(),
                'validation' => ['errors' => array_map('strval', $report->errors), 'warnings' => array_map('strval', $report->warnings)],
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<options=bold>Calendar</>', "{$definition->id()} — {$definition->name->original}");
        $this->components->twoColumnDetail('Version', "{$definition->version->number} ({$definition->version->status->value})");
        $this->components->twoColumnDetail('Scope', $definition->tenant() === '' ? 'global' : $definition->tenant());
        $this->components->twoColumnDetail('Driver', "{$definition->driver} ({$calendar->driver()->kind()->value} v{$calendar->driver()->version()})");
        $this->components->twoColumnDetail('Capabilities', implode(', ', $calendar->capabilities()->toArray()));
        $this->components->twoColumnDetail('Effective', (string) $definition->effective);
        $this->components->twoColumnDetail('Region', $definition->metadata->region ?? '—');
        $this->components->twoColumnDetail('Verification', $definition->metadata->verification->value);

        foreach ($definition->metadata->assumptions as $assumption) {
            $this->components->twoColumnDetail("Assumption: {$assumption->id}", "{$assumption->statement} [{$assumption->status->value}]");
        }

        foreach ($definition->cycles as $cycle) {
            $this->newLine();
            $this->components->info("Cycle {$cycle->id}: {$cycle->length()} positions, continuity {$cycle->continuity->value}, effective {$cycle->effective}");
            $this->table(['#', 'ID', 'Name (original)', 'Language'], array_map(static fn ($p): array => [$p->ordinal, $p->id, $p->label->original, $p->label->language ?? '—'], $cycle->positions));
            $this->table(['Anchor', 'Date', 'Position', 'Effective', 'Sources'], array_map(static fn ($a): array => [$a->id, $a->date->toString(), $a->positionId, (string) $a->effective, implode(', ', $a->sources)], $cycle->anchors));

            if ($cycle->exceptions !== []) {
                $this->table(['Override', 'Date', 'Position', 'Reason'], array_map(static fn ($e): array => [$e->id, $e->date->toString(), $e->positionId, $e->reason->original ?? '—'], $cycle->exceptions));
            }
        }

        $ids = $definition->metadata->sources;

        foreach ($definition->cycles as $cycle) {
            foreach ([...$cycle->anchors, ...$cycle->exceptions] as $item) {
                array_push($ids, ...$item->sources);
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids !== []) {
            $this->newLine();
            $this->components->info('Sources');

            foreach ($ids as $id) {
                $source = $sources->find($calendar->context(), $id);
                $this->components->twoColumnDetail($id, $source === null ? '<fg=yellow>not found</>' : "{$source->kind->value}, {$source->status->value}: {$source->title}");
            }
        }

        foreach ($report->errors as $error) {
            $this->components->error((string) $error);
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn((string) $warning);
        }

        return $report->passes() ? self::SUCCESS : self::FAILURE;
    }
}
