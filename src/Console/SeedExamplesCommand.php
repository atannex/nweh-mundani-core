<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Examples\LebialemCalendars;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Illuminate\Console\Command;

/**
 * Stores the bundled Lebialem definitions in the database as published
 * global calendars. Idempotent: identical content is skipped, and a
 * calendar that already exists with different content (edited locally, or
 * a newer version) is reported and left untouched.
 *
 * The one exception is an upgrade: when the bundled version is newer and the
 * stored one was itself published by this command, nobody has changed it
 * locally, so the bundled version is published over it.
 */
final class SeedExamplesCommand extends Command
{
    protected $signature = 'nweh-mundani:seed-examples {--dry-run : Report what would happen without writing}';

    protected $description = "Store the Nweh, Mundani (Wabane), M'Mouck and M'Mock reference calendars in the database";

    public function handle(CalendarRepository $calendars, SourceRepository $sources, CalendarWorkflowService $workflow, CalendarFactory $factory, AuditTrail $audit): int
    {
        if (! config('nweh-mundani.persistence.enabled')) {
            $this->components->error('Persistence is disabled (NWEH_MUNDANI_PERSISTENCE=false). The examples are still available in code when nweh-mundani.examples.register is true.');

            return self::FAILURE;
        }

        if (! $calendars->isAvailable()) {
            $this->components->error('The nmc_* tables are missing. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $context = new CalendarContext;
        $actor = new SystemActor('nweh-mundani:seed-examples');
        $examples = new LebialemCalendars;
        $dry = (bool) $this->option('dry-run');

        foreach ($examples->sources() as $data) {
            $source = $data instanceof Source ? $data : Source::fromArray($data);

            if ($sources->find('', $source->id) !== null) {
                $this->components->twoColumnDetail("source {$source->id}", 'exists, left untouched');
            } else {
                $dry || $workflow->saveSource($source, $actor, $context);
                $this->components->twoColumnDetail("source {$source->id}", $dry ? 'would create' : '<fg=green>created</>');
            }
        }

        foreach ($examples->calendars() as $data) {
            $definition = $data instanceof CalendarDefinition ? $data : $factory->fromArray($data);
            $id = $definition->id();
            $version = $definition->version->number;
            $existing = $calendars->findVersion('', $id, $version);
            $published = $calendars->findPublished('', $id);

            if ($existing !== null && $existing->contentHash() === $definition->contentHash()) {
                $this->components->twoColumnDetail($id, "v{$version} already stored, unchanged");

                continue;
            }

            $upgrade = $existing === null && $published !== null
                && version_compare($version, $published->version->number, '>')
                && $this->publishedBy($audit, $id, $actor->name);

            if (! $upgrade && ($existing !== null || ($published !== null && $published->version->number !== $version))) {
                $this->components->twoColumnDetail($id, '<fg=yellow>skipped</>: a stored version (v'.($existing ?? $published)->version->number.') differs from the bundled one; it was not overwritten');

                continue;
            }

            if (! $dry) {
                // The full review workflow, as the system actor (exempt from the
                // separate-approver rule because it is trusted code, not a person).
                $workflow->saveDraft($definition, $actor, $context);
                $workflow->submitForReview($id, $version, $actor, 'Bundled reference dataset', $context);
                $workflow->approve($id, $version, $actor, 'Bundled reference dataset', $context);
                $workflow->publish($id, $version, $actor, $context);
            }

            $from = $upgrade ? " (was v{$published->version->number})" : '';
            $this->components->twoColumnDetail($id, $dry ? "would publish v{$version}{$from}" : "<fg=green>published v{$version}</>{$from}");
        }

        return self::SUCCESS;
    }

    /** Whether the calendar's current published version was published by $actor. */
    private function publishedBy(AuditTrail $audit, string $id, string $actor): bool
    {
        $published = array_values(array_filter($audit->revisions('', 'calendar', $id), static fn ($entry): bool => $entry->action === 'published'));

        return $published !== [] && end($published)->actor === $actor;
    }
}
