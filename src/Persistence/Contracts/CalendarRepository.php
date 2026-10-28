<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Contracts;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Persistence\PublicationResult;
use Atannex\NwehMundani\Core\Persistence\VersionSummary;

/**
 * Stored calendar definitions and their versions.
 *
 * A version is an aggregate: its cycles, positions, anchors and overrides
 * are saved and loaded with it, and freeze with it on publication, which is
 * why they have no repositories of their own.
 *
 * Every method works on exactly one tenant key; '' is the global scope.
 * Falling back from a tenant to the global scope is the resolver's job.
 * Authorisation is the caller's job (see CalendarAdministration).
 */
interface CalendarRepository
{
    /** Whether the backing store is ready (for Eloquent: migrations have run). */
    public function isAvailable(): bool;

    /** The currently published version of a calendar. */
    public function findPublished(string $tenant, string $id): ?CalendarDefinition;

    /** A specific version in any status. */
    public function findVersion(string $tenant, string $id, string $version): ?CalendarDefinition;

    /** @return list<CalendarDefinition> The published version of every calendar of this tenant. */
    public function published(string $tenant): array;

    /** @return list<VersionSummary> Oldest first. */
    public function versions(string $tenant, string $id): array;

    /**
     * Create or replace a draft version.
     *
     * @throws ImmutableVersion when that version is already published or superseded.
     */
    public function saveDraft(CalendarDefinition $definition): void;

    /**
     * Publish a draft or approved version atomically: the previous published
     * version (if any) becomes superseded in the same transaction. Whether a
     * draft may skip review is decided by the workflow service, not here.
     *
     * @throws ImmutableVersion|InvalidTransition when the version cannot be published.
     */
    public function publish(string $tenant, string $id, string $version, ?string $actor = null): PublicationResult;

    /**
     * Move a version between workflow states other than publication
     * (draft ⇄ under-review ⇄ approved), recording who and why.
     *
     * @throws InvalidTransition
     */
    public function transition(string $tenant, string $id, string $version, VersionStatus $to, ?string $actor = null, ?string $notes = null): VersionSummary;

    /** Status of a stored calendar, or null when nothing is stored under the id. */
    public function calendarStatus(string $tenant, string $id): ?CalendarStatus;

    /** @return array<string, CalendarStatus> Every stored calendar of this tenant, by id. */
    public function calendarStatuses(string $tenant): array;

    /**
     * Make sure a calendar row exists for an id (with no versions), so a
     * code-registered calendar can carry a stored lifecycle status.
     */
    public function ensureCalendar(string $tenant, string $id): void;

    /** @throws CalendarNotFound when nothing is stored under the id. */
    public function setCalendarStatus(string $tenant, string $id, CalendarStatus $status, ?string $reason = null): void;
}
