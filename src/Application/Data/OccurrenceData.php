<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/** One computed occurrence of an observance on one day. */
final readonly class OccurrenceData extends Data
{
    /** @param list<string> $sources */
    public function __construct(
        public string $observance,
        public string $calendar,
        public string $name,
        public string $original,
        public string $category,
        public string $date,
        public string $scheduledFor,
        public string $state,
        public ?string $reason,
        public string $visibility,
        public string $verification,
        public array $sources,
        public int $priority,
    ) {}

    public function isCancelled(): bool
    {
        return $this->state === 'cancelled';
    }

    public function toArray(): array
    {
        return [
            'observance' => $this->observance,
            'calendar' => $this->calendar,
            'name' => $this->name,
            'original' => $this->original,
            'category' => $this->category,
            'date' => $this->date,
            'scheduled_for' => $this->scheduledFor,
            'state' => $this->state,
            'reason' => $this->reason,
            'visibility' => $this->visibility,
            'verification' => $this->verification,
            'sources' => $this->sources,
            'priority' => $this->priority,
        ];
    }
}
