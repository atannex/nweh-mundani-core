<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * A calendar's version history and change log. Actor names are included
 * only for viewers granted nweh-mundani.view-audit; otherwise they are null.
 */
final readonly class HistoryData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $versions
     * @param  list<array<string, mixed>>  $revisions
     * @param  list<array<string, mixed>>  $verifications
     */
    public function __construct(
        public string $calendar,
        public string $status,
        public array $versions,
        public array $revisions,
        public array $verifications,
        public bool $actorsVisible,
    ) {}

    public function toArray(): array
    {
        return [
            'calendar' => $this->calendar,
            'status' => $this->status,
            'versions' => $this->versions,
            'revisions' => $this->revisions,
            'verifications' => $this->verifications,
            'actors_visible' => $this->actorsVisible,
        ];
    }
}
