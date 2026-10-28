<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Dates;

use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Kernel\CalendarContext;
use Atannex\NwehMundani\Core\Localization\Localizer;

/**
 * Renders a CalendarDate with a placeholder pattern. Placeholders are
 * replaced literally (strtr), never evaluated:
 *
 *   {civil}                 2026-10-28
 *   {calendar}              nweh
 *   {label}                 primary cycle label
 *   {cycle:ID}              that cycle's label
 *   {cycle:ID:ordinal}      1-based position
 *   {cycle:ID:length}       cycle length
 *   {cycle:ID:original}     exact source spelling, never translated
 *   {component:NAME}        a numbered component
 *   {unit:NAME}             a named component's label (e.g. {unit:month})
 *   {unit:NAME:id}          its identifier
 */
final readonly class CalendarDateFormatter
{
    public const string DEFAULT_PATTERN = '{label}';

    public function __construct(
        private Localizer $localizer = new Localizer,
        private ?CalendarDefinition $definition = null,
    ) {}

    public function format(CalendarDate $date, ?string $locale = null, string $pattern = self::DEFAULT_PATTERN, ?CalendarContext $context = null): string
    {
        $replacements = [
            '{civil}' => $date->civil->toString(),
            '{calendar}' => $date->calendar,
            '{label}' => ($primary = $date->primary()) === null ? '' : $this->label($date, $primary, $locale, $context),
        ];

        foreach ($date->cycles as $id => $reading) {
            $replacements["{cycle:{$id}}"] = $this->label($date, $reading, $locale, $context);
            $replacements["{cycle:{$id}:ordinal}"] = (string) $reading->ordinal();
            $replacements["{cycle:{$id}:length}"] = (string) $reading->length;
            $replacements["{cycle:{$id}:original}"] = $reading->original();
        }

        foreach ($date->components as $name => $value) {
            $replacements["{component:{$name}}"] = (string) $value;
        }

        foreach ($date->identifiers as $unit => $id) {
            $replacements["{unit:{$unit}:id}"] = $id;
            $replacements["{unit:{$unit}}"] = $this->unitLabel($date, $unit, $id, $locale, $context);
        }

        return strtr($pattern, $replacements);
    }

    private function unitLabel(CalendarDate $date, string $unit, string $id, ?string $locale, ?CalendarContext $context): string
    {
        $name = $this->definition?->nameOf($unit, $id);

        return $name === null ? $id : $this->localizer->label($name->label, Localizer::nameKey($date->calendar, $unit, $id), $locale, $context);
    }

    private function label(CalendarDate $date, CycleReading $reading, ?string $locale, ?CalendarContext $context): string
    {
        return $this->localizer->label($reading->position->label, Localizer::positionKey($date->calendar, $reading->cycleId, $reading->positionId()), $locale, $context);
    }
}
