<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence;

use Atannex\NwehMundani\Core\Domain\VersionStatus;
use DateTimeImmutable;

/**
 * One stored version and its workflow bookkeeping, without the content.
 */
final readonly class VersionSummary
{
    public function __construct(
        public string $calendar,
        public string $version,
        public VersionStatus $status,
        public string $contentHash,
        public ?DateTimeImmutable $publishedAt = null,
        public ?string $submittedBy = null,
        public ?DateTimeImmutable $submittedAt = null,
        public ?string $reviewedBy = null,
        public ?DateTimeImmutable $reviewedAt = null,
        public ?string $reviewNotes = null,
        public ?string $publishedBy = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'calendar' => $this->calendar,
            'version' => $this->version,
            'status' => $this->status->value,
            'content_hash' => $this->contentHash,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
            'published_by' => $this->publishedBy,
            'submitted_at' => $this->submittedAt?->format(DATE_ATOM),
            'submitted_by' => $this->submittedBy,
            'reviewed_at' => $this->reviewedAt?->format(DATE_ATOM),
            'reviewed_by' => $this->reviewedBy,
            'review_notes' => $this->reviewNotes,
        ];
    }
}
