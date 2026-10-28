<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\InMemory;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\PublicationResult;
use Atannex\NwehMundani\Core\Persistence\VersionSummary;
use DateTimeImmutable;

/**
 * Process-local storage with the same rules as the Eloquent adapter. Used
 * when persistence is disabled and in tests.
 */
final class InMemoryCalendarRepository implements CalendarRepository
{
    /** @var array<string, array<string, array<string, CalendarDefinition>>> tenant => id => version => definition */
    private array $store = [];

    /** @var array<string, array<string, array<string, VersionSummary>>> tenant => id => version => workflow record */
    private array $workflow = [];

    /** @var array<string, array<string, CalendarStatus>> */
    private array $statuses = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function findPublished(string $tenant, string $id): ?CalendarDefinition
    {
        foreach ($this->store[$tenant][$id] ?? [] as $definition) {
            if ($definition->version->status === VersionStatus::Published) {
                return $definition;
            }
        }

        return null;
    }

    public function findVersion(string $tenant, string $id, string $version): ?CalendarDefinition
    {
        return $this->store[$tenant][$id][$version] ?? null;
    }

    public function published(string $tenant): array
    {
        $found = [];

        foreach (array_keys($this->store[$tenant] ?? []) as $id) {
            if (($definition = $this->findPublished($tenant, (string) $id)) !== null) {
                $found[] = $definition;
            }
        }

        return $found;
    }

    public function versions(string $tenant, string $id): array
    {
        $versions = array_values($this->workflow[$tenant][$id] ?? []);
        usort($versions, static fn (VersionSummary $a, VersionSummary $b): int => version_compare($a->version, $b->version));

        return $versions;
    }

    public function saveDraft(CalendarDefinition $definition): void
    {
        $tenant = $definition->tenant();
        $id = $definition->id();
        $number = $definition->version->number;
        $existing = $this->findVersion($tenant, $id, $number);

        if ($existing !== null && ! $existing->version->status->isMutable()) {
            throw ImmutableVersion::forVersion($id, $number, $existing->version->status->value);
        }

        $draft = $definition->withVersion($definition->version->withStatus(VersionStatus::Draft));
        $this->store[$tenant][$id][$number] = $draft;
        $this->workflow[$tenant][$id][$number] = new VersionSummary($id, $number, VersionStatus::Draft, $draft->contentHash());
        $this->statuses[$tenant][$id] ??= CalendarStatus::Active;
    }

    public function publish(string $tenant, string $id, string $version, ?string $actor = null): PublicationResult
    {
        $candidate = $this->findVersion($tenant, $id, $version) ?? throw CalendarNotFound::forIdentifier($id, $tenant, $version);
        $status = $candidate->version->status;

        if ($status->isReleased()) {
            throw ImmutableVersion::forVersion($id, $version, $status->value);
        }

        if (! $status->canMoveTo(VersionStatus::Published)) {
            throw InvalidTransition::between("Calendar [{$id}] version [{$version}]", $status->value, VersionStatus::Published->value, array_map(static fn (VersionStatus $s): string => $s->value, $status->allowedNext()));
        }

        $previous = $this->findPublished($tenant, $id);
        $now = new DateTimeImmutable;

        if ($previous !== null) {
            $this->store[$tenant][$id][$previous->version->number] = $previous->withVersion($previous->version->withStatus(VersionStatus::Superseded));
            $this->restamp($tenant, $id, $previous->version->number, VersionStatus::Superseded);
        }

        $published = $candidate->withVersion($candidate->version->withStatus(VersionStatus::Published, $now));
        $this->store[$tenant][$id][$version] = $published;
        $this->restamp($tenant, $id, $version, VersionStatus::Published, publishedAt: $now, publishedBy: $actor);

        return new PublicationResult($published, $previous?->version->number);
    }

    public function transition(string $tenant, string $id, string $version, VersionStatus $to, ?string $actor = null, ?string $notes = null): VersionSummary
    {
        $definition = $this->findVersion($tenant, $id, $version) ?? throw CalendarNotFound::forIdentifier($id, $tenant, $version);
        $from = $definition->version->status;

        if ($to === VersionStatus::Published || $to === VersionStatus::Superseded || ! $from->canMoveTo($to)) {
            throw InvalidTransition::between("Calendar [{$id}] version [{$version}]", $from->value, $to->value, array_map(static fn (VersionStatus $s): string => $s->value, $from->allowedNext()));
        }

        $this->store[$tenant][$id][$version] = $definition->withVersion($definition->version->withStatus($to));
        $now = new DateTimeImmutable;

        return $to === VersionStatus::UnderReview
            ? $this->restamp($tenant, $id, $version, $to, submittedBy: $actor, submittedAt: $now, notes: $notes)
            : $this->restamp($tenant, $id, $version, $to, reviewedBy: $actor, reviewedAt: $now, notes: $notes);
    }

    public function calendarStatus(string $tenant, string $id): ?CalendarStatus
    {
        return $this->statuses[$tenant][$id] ?? null;
    }

    public function calendarStatuses(string $tenant): array
    {
        return $this->statuses[$tenant] ?? [];
    }

    public function ensureCalendar(string $tenant, string $id): void
    {
        $this->statuses[$tenant][$id] ??= CalendarStatus::Active;
    }

    public function setCalendarStatus(string $tenant, string $id, CalendarStatus $status, ?string $reason = null): void
    {
        if (! isset($this->statuses[$tenant][$id])) {
            throw CalendarNotFound::forIdentifier($id, $tenant);
        }

        $this->statuses[$tenant][$id] = $status;
    }

    private function restamp(string $tenant, string $id, string $version, VersionStatus $status, ?DateTimeImmutable $publishedAt = null, ?string $publishedBy = null, ?string $submittedBy = null, ?DateTimeImmutable $submittedAt = null, ?string $reviewedBy = null, ?DateTimeImmutable $reviewedAt = null, ?string $notes = null): VersionSummary
    {
        $old = $this->workflow[$tenant][$id][$version];
        $definition = $this->store[$tenant][$id][$version];

        return $this->workflow[$tenant][$id][$version] = new VersionSummary(
            $id,
            $version,
            $status,
            $definition->contentHash(),
            $publishedAt ?? $old->publishedAt,
            $submittedBy ?? $old->submittedBy,
            $submittedAt ?? $old->submittedAt,
            $reviewedBy ?? $old->reviewedBy,
            $reviewedAt ?? $old->reviewedAt,
            $notes ?? $old->reviewNotes,
            $publishedBy ?? $old->publishedBy,
        );
    }
}
