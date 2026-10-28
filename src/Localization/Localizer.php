<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

use Atannex\NwehMundani\Core\Kernel\CalendarContext;

/**
 * Picks the text to show for a term, in this order: contextual providers
 * (tenant-aware, e.g. registered translations), plain providers, the term's
 * own translations, and finally the exact original.
 */
final class Localizer
{
    /** @var list<LocalizationProvider> */
    private array $providers = [];

    /** @var list<ContextualLocalizationProvider> */
    private array $contextual = [];

    public function addProvider(LocalizationProvider|ContextualLocalizationProvider $provider): void
    {
        if ($provider instanceof ContextualLocalizationProvider) {
            $this->contextual[] = $provider;
        }

        if ($provider instanceof LocalizationProvider) {
            $this->providers[] = $provider;
        }
    }

    public function label(Term $term, string $key, ?string $locale, ?CalendarContext $context = null): string
    {
        if ($locale !== null && $locale !== '') {
            if ($context !== null) {
                foreach ($this->contextual as $provider) {
                    $text = $provider->translateFor($context, $term, $key, $locale);

                    if ($text !== null && $text !== '') {
                        return $text;
                    }
                }
            }

            foreach ($this->providers as $provider) {
                $text = $provider->translate($term, $key, $locale);

                if ($text !== null && $text !== '') {
                    return $text;
                }
            }
        }

        return $term->label($locale);
    }

    /** Stable key of a calendar's name. */
    public static function calendarKey(string $calendar): string
    {
        return "calendar.{$calendar}.name";
    }

    public static function cycleKey(string $calendar, string $cycle): string
    {
        return "calendar.{$calendar}.cycle.{$cycle}.name";
    }

    public static function positionKey(string $calendar, string $cycle, string $position): string
    {
        return "calendar.{$calendar}.cycle.{$cycle}.position.{$position}";
    }

    /** Stable key of a named unit value, e.g. a month: calendar.<id>.unit.month.<name>. */
    public static function nameKey(string $calendar, string $unit, string $name): string
    {
        return "calendar.{$calendar}.unit.{$unit}.{$name}";
    }

    public static function observanceKey(string $calendar, string $observance, string $field = 'name'): string
    {
        return "calendar.{$calendar}.observance.{$observance}.{$field}";
    }
}
