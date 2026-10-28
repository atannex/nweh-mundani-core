<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application;

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarStatus;
use Atannex\NwehMundani\Core\Domain\VersionStatus;
use Atannex\NwehMundani\Core\Events\CalendarAnchorCorrected;
use Atannex\NwehMundani\Core\Events\CalendarApproved;
use Atannex\NwehMundani\Core\Events\CalendarDraftSaved;
use Atannex\NwehMundani\Core\Events\CalendarRejected;
use Atannex\NwehMundani\Core\Events\CalendarStatusChanged;
use Atannex\NwehMundani\Core\Events\CalendarSubmittedForReview;
use Atannex\NwehMundani\Core\Events\CalendarSuperseded;
use Atannex\NwehMundani\Core\Events\CalendarVersionPublished;
use Atannex\NwehMundani\Core\Events\VerificationRecorded;
use Atannex\NwehMundani\Core\Exceptions\CalendarNotFound;
use Atannex\NwehMundani\Core\Exceptions\DuplicateCalendar;
use Atannex\NwehMundani\Core\Exceptions\ImmutableVersion;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\InvalidTransition;
use Atannex\NwehMundani\Core\Exceptions\TenantViolation;
use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Atannex\NwehMundani\Core\Governance\Actor;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Governance\SourceCatalog;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Governance\Verification;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarFactory;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Kernel\CalendarResolver;
use Atannex\NwehMundani\Core\Kernel\CalendarValidator;
use Atannex\NwehMundani\Core\Kernel\ValidationReport;
use Atannex\NwehMundani\Core\Kernel\Violation;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\CalendarRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Atannex\NwehMundani\Core\Persistence\PublicationResult;
use Atannex\NwehMundani\Core\Persistence\VersionSummary;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Every state change to a stored calendar: creating and editing drafts,
 * review, approval, publication, suspension and archiving, anchors, cycles,
 * positions, sources and verification decisions.
 *
 * Each method authorises the actor for the context's tenant, refuses to
 * write outside that tenant, enforces the lifecycle, runs its writes and
 * their audit entry in one transaction, then invalidates caches and
 * dispatches after-commit events. Calling a method directly (bypassing any
 * controller) goes through exactly the same checks.
 *
 * $context defaults to the current context (TenantResolver). $actor is
 * always explicit: pass the authenticated user, or a SystemActor from
 * trusted code; null is anonymous and is denied.
 */
final readonly class CalendarWorkflowService
{
    public function __construct(
        private CalendarManager $manager,
        private CalendarRepository $calendars,
        private SourceRepository $sources,
        private SourceCatalog $sourceCatalog,
        private AuditTrail $audit,
        private UnitOfWork $unitOfWork,
        private CalendarAuthorizer $authorizer,
        private CalendarFactory $factory,
        private CalendarValidator $validator,
        private CalendarResolver $resolver,
        private DefinitionCache $cache,
        private ?Dispatcher $events = null,
        private bool $requireReview = true,
        private bool $separateApprover = true,
        private bool $requireSources = false,
    ) {}

    /**
     * Create a calendar that is not stored yet in this tenant, as its first draft.
     *
     * @param  CalendarDefinition|array<string, mixed>  $definition
     *
     * @throws DuplicateCalendar when the id is already stored in this tenant.
     */
    public function createCalendar(CalendarDefinition|array $definition, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        $context = $this->context($context);
        $definition = $this->definition($definition, $context);

        if ($this->calendars->calendarStatus($context->tenant, $definition->id()) !== null) {
            throw DuplicateCalendar::conflicting($definition->id(), $definition->version->number);
        }

        return $this->saveDraft($definition, $actor, $context);
    }

    /**
     * Save (or replace) a draft version in the context's tenant.
     *
     * @param  CalendarDefinition|array<string, mixed>  $definition
     */
    public function saveDraft(CalendarDefinition|array $definition, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        $context = $this->context($context);
        $definition = $this->definition($definition, $context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_CALENDARS, $actor, $context->tenant, $definition->id());
        $this->assertNoShadowing($context, $definition->id());
        $this->assertNotArchived($context, $definition->id());

        $draft = $definition->withVersion($definition->version->withStatus(VersionStatus::Draft));
        $who = Actor::name($actor);

        // Versions only move forward: a draft must be newer than anything
        // already released or in review, so history reads in order.
        foreach ($this->calendars->versions($draft->tenant(), $draft->id()) as $existing) {
            if ($existing->status !== VersionStatus::Draft && version_compare($existing->version, $draft->version->number) >= 0) {
                throw $existing->version === $draft->version->number
                    ? ImmutableVersion::forVersion($draft->id(), $existing->version, $existing->status->value)
                    : InvalidDefinition::single($draft->id(), 'version', "must be newer than {$existing->status->value} version {$existing->version}.");
            }
        }

        $this->unitOfWork->run(function () use ($draft, $who): void {
            $this->calendars->saveDraft($draft);
            $this->audit->recordRevision(new RevisionEntry($draft->tenant(), 'calendar', $draft->id(), 'draft-saved', $who, [
                'version' => $draft->version->number,
                'content_hash' => $draft->contentHash(),
            ]));
        });

        $this->cache->forgetCalendar($draft->tenant(), $draft->id());
        $this->events?->dispatch(new CalendarDraftSaved($draft->tenant(), $draft->id(), $draft->version->number, $draft->contentHash(), $who));

        return $draft;
    }

    /**
     * Start a new draft from an existing version (by default the published
     * one), as $newVersion (by default the next patch version).
     */
    public function createDraft(string $calendarId, Authenticatable|SystemActor|null $actor, ?string $newVersion = null, ?string $fromVersion = null, ?CalendarContext $context = null): CalendarDefinition
    {
        $context = $this->context($context);
        $base = $fromVersion === null
            ? $this->calendars->findPublished($context->tenant, $calendarId)
            : $this->calendars->findVersion($context->tenant, $calendarId, $fromVersion);

        if ($base === null) {
            throw CalendarNotFound::forIdentifier($calendarId, $context->tenant, $fromVersion);
        }

        $data = $base->toArray();
        $data['version'] = $newVersion ?? $this->nextPatch($context->tenant, $calendarId);
        $data['status'] = VersionStatus::Draft->value;
        $data['published_at'] = null;

        return $this->saveDraft($data, $actor, $context);
    }

    /**
     * Change top-level fields of a draft: name, driver, epoch, effective, metadata.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateCalendar(string $calendarId, string $version, array $changes, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        $allowed = ['name', 'driver', 'epoch', 'effective', 'metadata'];
        $unknown = array_diff(array_keys($changes), $allowed);

        if ($unknown !== []) {
            throw InvalidDefinition::single($calendarId, implode(', ', $unknown), 'only '.implode(', ', $allowed).' can be updated here; edit cycles with addCycle() and friends.');
        }

        return $this->editDraft($calendarId, $version, $actor, $context, static fn (array $data): array => array_replace($data, $changes));
    }

    /** Validate a stored version without changing it. */
    public function validate(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): ValidationReport
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_CALENDARS, $actor, $context->tenant, $calendarId);

        return $this->publicationReport($this->version($context, $calendarId, $version), $context);
    }

    /** Draft → under review. The definition must validate first. */
    public function submitForReview(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, ?string $notes = null, ?CalendarContext $context = null): VersionSummary
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_CALENDARS, $actor, $context->tenant, $calendarId);
        $who = Actor::name($actor);

        $summary = $this->unitOfWork->run(function () use ($context, $calendarId, $version, $who, $notes): VersionSummary {
            $definition = $this->version($context, $calendarId, $version);
            $this->publicationReport($definition, $context)->throwIfFailed();
            $summary = $this->calendars->transition($context->tenant, $calendarId, $version, VersionStatus::UnderReview, $who, $notes);
            $this->revision($context, $calendarId, 'submitted', $who, ['version' => $version]);

            return $summary;
        });

        $this->cache->forgetCalendar($context->tenant, $calendarId);
        $this->events?->dispatch(new CalendarSubmittedForReview($context->tenant, $calendarId, $version, $summary->contentHash, $who));

        return $summary;
    }

    /**
     * Under review → approved. With workflow.separate_approver (default) the
     * approver must not be the person who submitted the version.
     */
    public function approve(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, ?string $notes = null, ?CalendarContext $context = null): VersionSummary
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::REVIEW_CALENDARS, $actor, $context->tenant, $calendarId);
        $who = Actor::name($actor);

        $summary = $this->unitOfWork->run(function () use ($context, $calendarId, $version, $actor, $who, $notes): VersionSummary {
            $current = $this->summary($context, $calendarId, $version);

            if ($this->separateApprover && ! $actor instanceof SystemActor && $current->submittedBy !== null && $current->submittedBy === $who) {
                throw UnauthorizedModification::forAbility('approve a version you submitted yourself', "{$calendarId}@{$version}");
            }

            $this->publicationReport($this->version($context, $calendarId, $version), $context)->throwIfFailed();
            $summary = $this->calendars->transition($context->tenant, $calendarId, $version, VersionStatus::Approved, $who, $notes);
            $this->revision($context, $calendarId, 'approved', $who, ['version' => $version]);

            return $summary;
        });

        $this->cache->forgetCalendar($context->tenant, $calendarId);
        $this->events?->dispatch(new CalendarApproved($context->tenant, $calendarId, $version, $who));

        return $summary;
    }

    /** Under review or approved → back to draft, editable again. Notes say why. */
    public function reject(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, string $notes, ?CalendarContext $context = null): VersionSummary
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::REVIEW_CALENDARS, $actor, $context->tenant, $calendarId);
        $who = Actor::name($actor);

        [$summary, $from] = $this->unitOfWork->run(function () use ($context, $calendarId, $version, $who, $notes): array {
            $from = $this->summary($context, $calendarId, $version)->status;

            if ($from === VersionStatus::Draft) {
                throw InvalidTransition::between("Calendar [{$calendarId}] version [{$version}]", $from->value, VersionStatus::Draft->value, [VersionStatus::UnderReview->value]);
            }

            $summary = $this->calendars->transition($context->tenant, $calendarId, $version, VersionStatus::Draft, $who, $notes);
            $this->revision($context, $calendarId, 'rejected', $who, ['version' => $version, 'from' => $from->value]);

            return [$summary, $from];
        });

        $this->cache->forgetCalendar($context->tenant, $calendarId);
        $this->events?->dispatch(new CalendarRejected($context->tenant, $calendarId, $version, $from->value, $who));

        return $summary;
    }

    /**
     * Publish a version: validate identity, driver, structure, anchors and
     * ranges; check the tenancy policy and publication requirements; then
     * supersede the previous version and publish, atomically. Caches are
     * invalidated and events dispatched only after the commit.
     *
     * With workflow.require_review (default) only an approved version can
     * be published; otherwise a draft can be published directly.
     *
     * @throws InvalidDefinition|InvalidTransition|CalendarNotFound|UnauthorizedModification
     */
    public function publish(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): PublicationResult
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::PUBLISH_CALENDARS, $actor, $context->tenant, $calendarId);
        $this->assertNotArchived($context, $calendarId);
        $who = Actor::name($actor);

        $result = $this->unitOfWork->run(function () use ($context, $calendarId, $version, $who): PublicationResult {
            $candidate = $this->version($context, $calendarId, $version);
            $status = $candidate->version->status;

            if ($this->requireReview && $status === VersionStatus::Draft) {
                throw InvalidTransition::because("Calendar [{$calendarId}] version [{$version}]", 'must be submitted, reviewed and approved before publication (nweh-mundani.workflow.require_review).');
            }

            $this->publicationReport($candidate, $context)->throwIfFailed();
            $this->assertNoShadowing($context, $calendarId);

            $result = $this->calendars->publish($context->tenant, $calendarId, $version, $who);
            $this->revision($context, $calendarId, 'published', $who, [
                'version' => $version,
                'superseded' => $result->superseded,
                'content_hash' => $result->definition->contentHash(),
            ]);

            return $result;
        });

        $this->cache->forgetCalendar($context->tenant, $calendarId);
        $this->events?->dispatch(new CalendarVersionPublished($context->tenant, $calendarId, $version, $result->superseded, $result->definition->contentHash(), $who));

        if ($result->superseded !== null) {
            $this->events?->dispatch(new CalendarSuperseded($context->tenant, $calendarId, $result->superseded, $version, $who));
        }

        return $result;
    }

    public function suspend(string $calendarId, Authenticatable|SystemActor|null $actor, ?string $reason = null, ?CalendarContext $context = null): CalendarStatus
    {
        return $this->changeStatus($calendarId, CalendarStatus::Suspended, $actor, $reason, $context);
    }

    public function archive(string $calendarId, Authenticatable|SystemActor|null $actor, ?string $reason = null, ?CalendarContext $context = null): CalendarStatus
    {
        return $this->changeStatus($calendarId, CalendarStatus::Archived, $actor, $reason, $context);
    }

    public function reactivate(string $calendarId, Authenticatable|SystemActor|null $actor, ?string $reason = null, ?CalendarContext $context = null): CalendarStatus
    {
        return $this->changeStatus($calendarId, CalendarStatus::Active, $actor, $reason, $context);
    }

    /**
     * Add a cycle to a draft.
     *
     * @param  array<string, mixed>  $cycle  same shape as a definition's cycles[] entry
     */
    public function addCycle(string $calendarId, string $version, array $cycle, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        return $this->editDraft($calendarId, $version, $actor, $context, static function (array $data) use ($cycle): array {
            $data['cycles'][] = $cycle;

            return $data;
        });
    }

    /**
     * Change a position's label (the term) in a draft. Its identifier and
     * ordinal stay fixed, so rules and anchors keep pointing at it.
     *
     * @param  string|array<string, mixed>  $label
     */
    public function updateCyclePosition(string $calendarId, string $version, string $cycleId, string $positionId, string|array $label, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        return $this->editDraft($calendarId, $version, $actor, $context, function (array $data) use ($calendarId, $cycleId, $positionId, $label): array {
            $c = $this->cycleIndex($data, $calendarId, $cycleId);
            $p = $this->itemIndex($data['cycles'][$c]['positions'], $positionId, $calendarId, "cycles.{$cycleId}.positions");
            $data['cycles'][$c]['positions'][$p]['label'] = $label;

            return $data;
        });
    }

    /** @param array<string, mixed> $anchor {id, date, position, effective?, sources?} */
    public function addAnchor(string $calendarId, string $version, string $cycleId, array $anchor, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        return $this->editDraft($calendarId, $version, $actor, $context, function (array $data) use ($calendarId, $cycleId, $anchor): array {
            $data['cycles'][$this->cycleIndex($data, $calendarId, $cycleId)]['anchors'][] = $anchor;

            return $data;
        });
    }

    /**
     * Correct an anchor in a draft (date, position, effective range,
     * sources). Published history is untouched: the correction takes effect
     * when this draft is published as a new version.
     *
     * @param  array<string, mixed>  $changes
     */
    public function correctAnchor(string $calendarId, string $version, string $cycleId, string $anchorId, array $changes, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        $unknown = array_diff(array_keys($changes), ['date', 'position', 'effective', 'sources']);

        if ($unknown !== []) {
            throw InvalidDefinition::single($calendarId, implode(', ', $unknown), 'an anchor correction may change date, position, effective and sources only.');
        }

        $before = null;

        $definition = $this->editDraft($calendarId, $version, $actor, $context, function (array $data) use ($calendarId, $cycleId, $anchorId, $changes, &$before): array {
            $c = $this->cycleIndex($data, $calendarId, $cycleId);
            $a = $this->itemIndex($data['cycles'][$c]['anchors'], $anchorId, $calendarId, "cycles.{$cycleId}.anchors");
            $before = $data['cycles'][$c]['anchors'][$a];
            $data['cycles'][$c]['anchors'][$a] = array_replace($before, $changes);

            return $data;
        });

        $anchor = null;

        foreach ($definition->cycle($cycleId)->anchors ?? [] as $candidate) {
            if ($candidate->id === $anchorId) {
                $anchor = $candidate;
            }
        }

        if ($anchor !== null && is_array($before)) {
            $context = $this->context($context);
            $this->events?->dispatch(new CalendarAnchorCorrected(
                $context->tenant, $calendarId, $version, $cycleId, $anchorId,
                (string) ($before['date'] ?? ''), (string) ($before['position'] ?? ''),
                $anchor->date->toString(), $anchor->positionId, Actor::name($actor),
            ));
        }

        return $definition;
    }

    /** @param array<string, mixed> $exception {id, date, position, reason?, sources?}: a one-day historical correction */
    public function addException(string $calendarId, string $version, string $cycleId, array $exception, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): CalendarDefinition
    {
        return $this->editDraft($calendarId, $version, $actor, $context, function (array $data) use ($calendarId, $cycleId, $exception): array {
            $data['cycles'][$this->cycleIndex($data, $calendarId, $cycleId)]['exceptions'][] = $exception;

            return $data;
        });
    }

    public function saveSource(Source $source, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): Source
    {
        $context = $this->context($context);

        if ($source->tenant !== $context->tenant) {
            throw TenantViolation::crossing($context->tenant, $source->tenant, "Source [{$source->id}]");
        }

        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_SOURCES, $actor, $context->tenant, $source->id);
        $who = Actor::name($actor);

        $this->unitOfWork->run(function () use ($source, $who): void {
            $this->sources->save($source);
            $this->audit->recordRevision(new RevisionEntry($source->tenant, 'source', $source->id, 'saved', $who, ['kind' => $source->kind->value]));
        });

        return $source;
    }

    /**
     * Cite a source from a draft: on the definition as a whole, or on one
     * anchor ($cycleId + $anchorId). The source must be visible from the context.
     */
    public function attachSource(string $calendarId, string $version, string $sourceId, Authenticatable|SystemActor|null $actor, ?string $cycleId = null, ?string $anchorId = null, ?CalendarContext $context = null): CalendarDefinition
    {
        $context = $this->context($context);

        if ($this->sourceCatalog->find($context, $sourceId) === null) {
            throw InvalidDefinition::single($calendarId, 'sources', "source [{$sourceId}] is not on record in this scope; save it first.");
        }

        return $this->editDraft($calendarId, $version, $actor, $context, function (array $data) use ($calendarId, $sourceId, $cycleId, $anchorId): array {
            if ($cycleId === null || $anchorId === null) {
                $data['metadata']['sources'] = array_values(array_unique([...($data['metadata']['sources'] ?? []), $sourceId]));

                return $data;
            }

            $c = $this->cycleIndex($data, $calendarId, $cycleId);
            $a = $this->itemIndex($data['cycles'][$c]['anchors'], $anchorId, $calendarId, "cycles.{$cycleId}.anchors");
            $data['cycles'][$c]['anchors'][$a]['sources'] = array_values(array_unique([...($data['cycles'][$c]['anchors'][$a]['sources'] ?? []), $sourceId]));

            return $data;
        });
    }

    public function recordVerification(string $subjectType, string $subjectKey, VerificationStatus $status, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null, ?string $notes = null): Verification
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::VERIFY, $actor, $context->tenant, "{$subjectType}:{$subjectKey}");

        $history = $this->audit->verifications($context->tenant, $subjectType, $subjectKey);
        $current = $history === [] ? null : end($history)->status;

        if ($current !== null && ! $current->canMoveTo($status)) {
            throw new InvalidDefinition("{$subjectType}:{$subjectKey}", [new Violation('status', "cannot move from {$current->value} to {$status->value}; allowed: ".(implode(', ', array_map(static fn (VerificationStatus $s): string => $s->value, $current->allowedNext())) ?: 'none').'.')]);
        }

        $verification = new Verification($context->tenant, $subjectType, $subjectKey, $status, Actor::name($actor), $notes);

        $this->unitOfWork->run(fn () => $this->audit->recordVerification($verification));
        $this->events?->dispatch(new VerificationRecorded($context->tenant, $subjectType, $subjectKey, $status->value, $current?->value, $verification->actor));

        return $verification;
    }

    /**
     * Load a draft as data, apply a change, re-validate it through the
     * factory and save it, all as one audited write.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function editDraft(string $calendarId, string $version, Authenticatable|SystemActor|null $actor, ?CalendarContext $context, Closure $change): CalendarDefinition
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_CALENDARS, $actor, $context->tenant, $calendarId);
        $draft = $this->version($context, $calendarId, $version);

        if (! $draft->version->status->isMutable()) {
            throw ImmutableVersion::forVersion($calendarId, $version, $draft->version->status->value);
        }

        $edited = $this->factory->fromArray($change($draft->toArray()), $context->tenant);

        if ($edited->id() !== $calendarId || $edited->version->number !== $version) {
            throw InvalidDefinition::single($calendarId, 'id', 'a draft edit cannot change the calendar id or version; use createDraft() for a new version.');
        }

        return $this->saveDraft($edited, $actor, $context);
    }

    private function changeStatus(string $calendarId, CalendarStatus $to, Authenticatable|SystemActor|null $actor, ?string $reason, ?CalendarContext $context): CalendarStatus
    {
        $context = $this->context($context);
        $this->authorizer->authorize(CalendarAuthorizer::ARCHIVE_CALENDARS, $actor, $context->tenant, $calendarId);
        $who = Actor::name($actor);

        $from = $this->unitOfWork->run(function () use ($context, $calendarId, $to, $reason, $who): CalendarStatus {
            $from = $this->calendars->calendarStatus($context->tenant, $calendarId);

            if ($from === null) {
                // A code-registered calendar has no row yet: create one that
                // carries only its status. It must belong to this exact scope,
                // so a tenant can never suspend a global calendar for everyone.
                if (! $this->resolver->isCodeRegistered($context->tenant, $calendarId)) {
                    throw ! $context->isGlobal() && $this->resolver->globalExists($calendarId)
                        ? TenantViolation::crossing($context->tenant, '', "Calendar [{$calendarId}]")
                        : CalendarNotFound::forIdentifier($calendarId, $context->tenant);
                }

                $this->calendars->ensureCalendar($context->tenant, $calendarId);
                $from = CalendarStatus::Active;
            }

            if (! $from->canMoveTo($to)) {
                throw InvalidTransition::between("Calendar [{$calendarId}]", $from->value, $to->value, array_map(static fn (CalendarStatus $s): string => $s->value, $from->allowedNext()));
            }

            $this->calendars->setCalendarStatus($context->tenant, $calendarId, $to, $reason);
            $this->revision($context, $calendarId, "status-{$to->value}", $who, ['from' => $from->value, 'reason' => $reason]);

            return $from;
        });

        $this->cache->forgetCalendar($context->tenant, $calendarId);
        $this->events?->dispatch(new CalendarStatusChanged($context->tenant, $calendarId, $from->value, $to->value, $who));

        return $to;
    }

    /**
     * Mathematical validity plus publication requirements (sources when
     * workflow.require_sources is on). Historical verification is separate
     * and never part of this report.
     */
    private function publicationReport(CalendarDefinition $definition, CalendarContext $context): ValidationReport
    {
        $report = $this->validator->validate($definition);

        if (! $this->requireSources) {
            return $report;
        }

        $errors = [];

        if ($definition->metadata->sources === []) {
            $errors[] = new Violation('metadata.sources', 'publication requires at least one source (nweh-mundani.workflow.require_sources).');
        }

        foreach ($definition->metadata->sources as $id) {
            if ($this->sourceCatalog->find($context, $id) === null) {
                $errors[] = new Violation('metadata.sources', "source [{$id}] is not on record.");
            }
        }

        return $report->merge(new ValidationReport($definition->id(), $errors));
    }

    private function assertNoShadowing(CalendarContext $context, string $calendarId): void
    {
        if (! $context->isGlobal() && ! $this->resolver->allowsShadowing() && $this->resolver->globalExists($calendarId)) {
            throw InvalidDefinition::single($calendarId, 'id', 'a global calendar already uses this identifier and nweh-mundani.tenancy.allow_shadowing is off; choose another identifier.');
        }
    }

    private function assertNotArchived(CalendarContext $context, string $calendarId): void
    {
        if ($this->calendars->calendarStatus($context->tenant, $calendarId) === CalendarStatus::Archived) {
            throw InvalidTransition::because("Calendar [{$calendarId}]", 'is archived; reactivate it before changing it.');
        }
    }

    private function version(CalendarContext $context, string $calendarId, string $version): CalendarDefinition
    {
        return $this->calendars->findVersion($context->tenant, $calendarId, $version)
            ?? throw CalendarNotFound::forIdentifier($calendarId, $context->tenant, $version);
    }

    private function summary(CalendarContext $context, string $calendarId, string $version): VersionSummary
    {
        foreach ($this->calendars->versions($context->tenant, $calendarId) as $summary) {
            if ($summary->version === $version) {
                return $summary;
            }
        }

        throw CalendarNotFound::forIdentifier($calendarId, $context->tenant, $version);
    }

    private function nextPatch(string $tenant, string $calendarId): string
    {
        $versions = array_map(static fn (VersionSummary $v): string => $v->version, $this->calendars->versions($tenant, $calendarId));
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));
        $latest = end($versions) ?: '0.0.0';
        [$major, $minor, $patch] = array_map('intval', explode('.', (string) preg_replace('/-.*$/', '', $latest)) + [0, 0, 0]);

        return "{$major}.{$minor}.".($patch + 1);
    }

    /** @param array<string, mixed> $data */
    private function cycleIndex(array $data, string $calendarId, string $cycleId): int
    {
        return $this->itemIndex(is_array($data['cycles'] ?? null) ? $data['cycles'] : [], $cycleId, $calendarId, 'cycles');
    }

    /** @param array<mixed> $items */
    private function itemIndex(array $items, string $id, string $calendarId, string $path): int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        throw InvalidDefinition::single($calendarId, $path, "has no entry [{$id}].");
    }

    /** @param array<string, scalar|null> $changes */
    private function revision(CalendarContext $context, string $calendarId, string $action, ?string $who, array $changes): void
    {
        $this->audit->recordRevision(new RevisionEntry($context->tenant, 'calendar', $calendarId, $action, $who, $changes));
    }

    /**
     * @param  CalendarDefinition|array<string, mixed>  $definition
     */
    private function definition(CalendarDefinition|array $definition, CalendarContext $context): CalendarDefinition
    {
        $definition = is_array($definition) ? $this->factory->fromArray($definition, $context->tenant) : $definition;

        if ($definition->tenant() !== $context->tenant) {
            throw TenantViolation::crossing($context->tenant, $definition->tenant(), "Calendar [{$definition->id()}]");
        }

        return $definition;
    }

    private function context(?CalendarContext $context): CalendarContext
    {
        return $context ?? $this->manager->context();
    }
}
