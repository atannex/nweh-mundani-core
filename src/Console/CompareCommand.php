<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Console;

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Application\Data\CalendarDayData;
use Atannex\NwehMundani\Core\Exceptions\NwehMundaniException;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class CompareCommand extends Command
{
    use ResolvesContext;

    protected $signature = 'nweh-mundani:compare {date : Civil date YYYY-MM-DD} {calendars?* : Calendar identifiers (default: all)} {--tenant=} {--locale=} {--json : Output JSON}';

    protected $description = 'Compare how several calendars represent one civil date';

    public function handle(CalendarQueryService $queries): int
    {
        $context = $this->manager()->context();
        $ids = $this->argument('calendars');

        try {
            $comparison = $queries->compare($this->stringArgument('date'), is_array($ids) && $ids !== [] ? array_values(array_map('strval', $ids)) : null, $context);
        } catch (NwehMundaniException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(static function (CalendarDayData $day): array {
            $cycle = $day->cycles[0] ?? null;

            return [
                'calendar' => $day->calendar,
                'status' => $day->status,
                'date' => self::nativeDate($day),
                'position' => $cycle === null ? null : "{$cycle->ordinal}/{$cycle->length}",
                'label' => $day->label(),
                'certainty' => $day->certainty,
                'notes' => implode(' ', $day->notes),
            ];
        }, $comparison->calendars);

        if ($this->option('json')) {
            $this->json(['date' => $comparison->date, 'calendars' => array_values($rows)]);

            return self::SUCCESS;
        }

        $this->components->info("Civil date {$comparison->date}");
        $this->table(['Calendar', 'Status', 'Date', 'Position', 'Label', 'Certainty', 'Notes'], array_values($rows));

        return self::SUCCESS;
    }

    /** "17 Marheshvan 5787" for year/month/day calendars, null for purely cyclic ones. */
    public static function nativeDate(CalendarDayData $day): ?string
    {
        if (! isset($day->components['year'], $day->components['day'])) {
            return null;
        }

        return trim($day->components['day'].' '.($day->unit('month') ?? (string) ($day->components['month'] ?? '')).' '.$day->components['year']);
    }
}
