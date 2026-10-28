<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Atannex\NwehMundani\Core\Application\CalendarWorkflowService;
use Atannex\NwehMundani\Core\Application\ObservanceManagementService;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Observances\Observance;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Persistence\PublicationResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The original write API, kept for compatibility. Every method delegates to
 * CalendarWorkflowService or ObservanceManagementService, which hold the
 * rules; new code should use those services directly.
 */
final readonly class CalendarAdministration
{
    public function __construct(
        private CalendarWorkflowService $workflow,
        private ObservanceManagementService $observances,
        private ObservanceFactory $observanceFactory,
    ) {}

    /** @param CalendarDefinition|array<string, mixed> $definition */
    public function saveDraft(CalendarDefinition|array $definition, Authenticatable|SystemActor|null $actor, CalendarContext $context): CalendarDefinition
    {
        return $this->workflow->saveDraft($definition, $actor, $context);
    }

    public function publish(string $id, string $version, Authenticatable|SystemActor|null $actor, CalendarContext $context): PublicationResult
    {
        return $this->workflow->publish($id, $version, $actor, $context);
    }

    /** @param Observance|array<string, mixed> $observance */
    public function saveObservance(Observance|array $observance, Authenticatable|SystemActor|null $actor, CalendarContext $context): Observance
    {
        $observance = is_array($observance) ? $this->observanceFactory->fromArray($observance, $context->tenant) : $observance;

        return $this->observances->save($observance, $actor, $context);
    }

    public function deleteObservance(string $calendar, string $id, Authenticatable|SystemActor|null $actor, CalendarContext $context): bool
    {
        return $this->observances->delete($calendar, $id, $actor, $context);
    }

    public function recordVerification(string $subjectType, string $subjectKey, VerificationStatus $status, Authenticatable|SystemActor|null $actor, CalendarContext $context, ?string $notes = null): Verification
    {
        return $this->workflow->recordVerification($subjectType, $subjectKey, $status, $actor, $context, $notes);
    }

    public function saveSource(Source $source, Authenticatable|SystemActor|null $actor, CalendarContext $context): Source
    {
        return $this->workflow->saveSource($source, $actor, $context);
    }

    public static function actorName(Authenticatable|SystemActor|null $actor): ?string
    {
        return Actor::name($actor);
    }
}
