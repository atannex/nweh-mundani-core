<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

/**
 * What a calendar is, without its full definition: enough to list,
 * title and badge it. $status is the calendar's lifecycle status
 * (active | suspended | archived); $verification is the provenance status of
 * its content. Mathematical validity is reported by validate(), separately.
 */
final readonly class CalendarSummaryData extends Data
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<array{id: string, name: string, length: int, continuity: string}>  $cycles
     * @param  list<array{id: string, statement: string, status: string}>  $unverifiedAssumptions
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $original,
        public ?string $language,
        public string $scope,
        public string $version,
        public string $versionStatus,
        public string $status,
        public string $driver,
        public string $driverKind,
        public array $capabilities,
        public array $cycles,
        public string $verification,
        public array $unverifiedAssumptions,
        public ?string $region,
        public array $tags,
        public ?string $effectiveFrom,
        public ?string $effectiveUntil,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'original' => $this->original,
            'language' => $this->language,
            'scope' => $this->scope,
            'version' => $this->version,
            'version_status' => $this->versionStatus,
            'status' => $this->status,
            'driver' => $this->driver,
            'driver_kind' => $this->driverKind,
            'capabilities' => $this->capabilities,
            'cycles' => $this->cycles,
            'verification' => $this->verification,
            'unverified_assumptions' => $this->unverifiedAssumptions,
            'region' => $this->region,
            'tags' => $this->tags,
            'effective' => ['from' => $this->effectiveFrom, 'until' => $this->effectiveUntil],
        ];
    }
}
