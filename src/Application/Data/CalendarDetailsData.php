<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

use Atannex\NwehMundani\Core\Kernel\ValidationReport;

/**
 * Everything about one calendar version: summary, full cycle structure,
 * the sources the viewer may see, and its validation report. A referenced
 * source the viewer may not see is listed in $hiddenSources by id count only.
 */
final readonly class CalendarDetailsData extends Data
{
    /**
     * @param  list<CycleData>  $cycles
     * @param  list<SourceData>  $sources
     * @param  list<string>  $missingSources  referenced ids that are not on record
     * @param  array<string, scalar|null>  $extra
     */
    public function __construct(
        public CalendarSummaryData $summary,
        public ?string $description,
        public ?string $epoch,
        public array $cycles,
        public array $sources,
        public int $hiddenSources,
        public array $missingSources,
        public array $extra,
        public ?string $publishedAt,
        public ValidationReport $validation,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->summary->toArray(),
            'description' => $this->description,
            'epoch' => $this->epoch,
            'published_at' => $this->publishedAt,
            'cycles' => self::all($this->cycles),
            'sources' => self::all($this->sources),
            'hidden_sources' => $this->hiddenSources,
            'missing_sources' => $this->missingSources,
            'extra' => $this->extra,
            'validation' => [
                'valid' => $this->validation->passes(),
                'errors' => array_map('strval', $this->validation->errors),
                'warnings' => array_map('strval', $this->validation->warnings),
            ],
        ];
    }
}
