<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application;

use Atannex\NwehMundani\Core\Events\TranslationRegistered;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Governance\Actor;
use Atannex\NwehMundani\Core\Governance\CalendarAuthorizer;
use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\SystemActor;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Atannex\NwehMundani\Core\Localization\Localizer;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Localization\TranslationCatalog;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Contracts\TranslationRepository;
use Atannex\NwehMundani\Core\Persistence\Contracts\UnitOfWork;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;

/**
 * Register translations of a calendar's terms without republishing it.
 *
 * Translations are keyed by stable term paths (see Localizer::*Key()), never
 * by translated text, and live beside the version: the authoritative
 * original and the version's content hash are untouched. Registered
 * translations win over those carried by the version when rendering.
 */
final readonly class TranslationManagementService
{
    public function __construct(
        private CalendarManager $manager,
        private TranslationRepository $translations,
        private TranslationCatalog $catalog,
        private AuditTrail $audit,
        private UnitOfWork $unitOfWork,
        private CalendarAuthorizer $authorizer,
        private ?Dispatcher $events = null,
    ) {}

    public function register(string $calendarId, string $termKey, string $locale, string $text, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): void
    {
        $context = $context ?? $this->manager->context();
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_TRANSLATIONS, $actor, $context->tenant, $calendarId);
        $this->assertKnownTerm($context, $calendarId, $termKey);

        try {
            // Same rules as any term text: non-empty, valid UTF-8, valid locale; stored unchanged.
            new Term($text, translations: [$locale => $text]);
        } catch (InvalidArgumentException $e) {
            throw InvalidDefinition::single($calendarId, $termKey, $e->getMessage());
        }

        $who = Actor::name($actor);

        $this->unitOfWork->run(function () use ($context, $calendarId, $termKey, $locale, $text, $who): void {
            $this->translations->save($context->tenant, $calendarId, $termKey, $locale, $text, $who);
            $this->audit->recordRevision(new RevisionEntry($context->tenant, 'translation', "{$calendarId}/{$termKey}", 'registered', $who, ['locale' => $locale]));
        });

        $this->catalog->forget($context->tenant, $calendarId);
        $this->events?->dispatch(new TranslationRegistered($context->tenant, $calendarId, $termKey, $locale, 'registered', $who));
    }

    public function remove(string $calendarId, string $termKey, string $locale, Authenticatable|SystemActor|null $actor, ?CalendarContext $context = null): bool
    {
        $context = $context ?? $this->manager->context();
        $this->authorizer->authorize(CalendarAuthorizer::MANAGE_TRANSLATIONS, $actor, $context->tenant, $calendarId);
        $who = Actor::name($actor);

        $removed = $this->unitOfWork->run(function () use ($context, $calendarId, $termKey, $locale, $who): bool {
            $removed = $this->translations->delete($context->tenant, $calendarId, $termKey, $locale);

            if ($removed) {
                $this->audit->recordRevision(new RevisionEntry($context->tenant, 'translation', "{$calendarId}/{$termKey}", 'removed', $who, ['locale' => $locale]));
            }

            return $removed;
        });

        if ($removed) {
            $this->catalog->forget($context->tenant, $calendarId);
            $this->events?->dispatch(new TranslationRegistered($context->tenant, $calendarId, $termKey, $locale, 'removed', $who));
        }

        return $removed;
    }

    /** The key must name a term of the calendar the context resolves (or one of its observances). */
    private function assertKnownTerm(CalendarContext $context, string $calendarId, string $termKey): void
    {
        $calendar = $this->manager->withContext($context)->calendar($calendarId);
        $definition = $calendar->definition();
        $keys = [Localizer::calendarKey($calendarId) => true];

        foreach ($definition->cycles as $cycle) {
            $keys[Localizer::cycleKey($calendarId, $cycle->id)] = true;

            foreach ($cycle->positions as $position) {
                $keys[Localizer::positionKey($calendarId, $cycle->id, $position->id)] = true;
            }
        }

        foreach ($definition->names as $unit => $names) {
            foreach ($names as $name) {
                $keys[Localizer::nameKey($calendarId, $unit, $name->id)] = true;
            }
        }

        foreach ($calendar->observanceService()->visible($context, $calendarId, null, includeUnpublished: true) as $observance) {
            $keys[Localizer::observanceKey($calendarId, $observance->id)] = true;
            $keys[Localizer::observanceKey($calendarId, $observance->id, 'description')] = true;
        }

        if (! isset($keys[$termKey])) {
            throw InvalidDefinition::single($calendarId, $termKey, 'is not a term of this calendar. Keys look like calendar.<id>.cycle.<cycle>.position.<position>.');
        }
    }
}
