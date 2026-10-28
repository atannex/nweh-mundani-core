<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

use Atannex\NwehMundani\Core\Observances\Observance;

/** An observance definition (the rule), as opposed to one occurrence of it. */
final readonly class ObservanceData extends Data
{
    /** @param list<OccurrenceData> $occurrences occurrences in the requested window, when one was given */
    public function __construct(
        public Observance $observance,
        public string $name,
        public ?string $description,
        public array $occurrences = [],
    ) {}

    public function toArray(): array
    {
        $data = $this->observance->toArray();
        unset($data['tenant']);

        return [
            ...$data,
            'name' => $this->name,
            'original' => $this->observance->name->original,
            'description' => $this->description,
            'occurrences' => self::all($this->occurrences),
        ];
    }
}
