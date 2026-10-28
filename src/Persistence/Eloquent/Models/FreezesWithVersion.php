<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent\Models;

use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rows that belong to a calendar version cannot be created, changed or
 * removed once that version has left draft.
 *
 * The check sits in Eloquent's own write methods (performInsert,
 * performUpdate, performDeleteOnModel) rather than in model-event listeners,
 * so it cannot be switched off by Event::fake(), withoutEvents() or a
 * listener that returns early. Query-builder bulk writes
 * (Model::query()->update()) bypass models by design; the package never
 * uses them on version content outside drafts.
 */
trait FreezesWithVersion
{
    abstract public function owningVersion(): ?CalendarVersionRecord;

    /** @param Builder<static> $query */
    protected function performInsert(Builder $query): bool
    {
        $this->assertVersionIsDraft();

        return parent::performInsert($query);
    }

    /** @param Builder<static> $query */
    protected function performUpdate(Builder $query): bool
    {
        $this->assertVersionIsDraft();

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        $this->assertVersionIsDraft();

        parent::performDeleteOnModel();
    }

    private function assertVersionIsDraft(): void
    {
        $version = $this->owningVersion();

        if ($version !== null && $version->status !== VersionStatus::Draft->value) {
            throw ImmutableVersion::forVersion($version->calendar->identifier ?? '?', $version->version, $version->status);
        }
    }
}
