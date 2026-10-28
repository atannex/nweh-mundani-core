<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Facades;

use Atannex\NwehMundani\Core\Kernel\CalendarManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Atannex\NwehMundani\Core\Kernel\Calendar calendar(string $id, ?string $version = null)
 * @method static bool has(string $id)
 * @method static array<string, \Atannex\NwehMundani\Core\Kernel\Calendar> calendars()
 * @method static \Atannex\NwehMundani\Core\Dates\CalendarDateComparison compare(\Atannex\NwehMundani\Core\Dates\CivilDate|string|\DateTimeInterface $date, ?list<string> $ids = null)
 * @method static \Atannex\NwehMundani\Core\Domain\CalendarDefinition register(\Atannex\NwehMundani\Core\Domain\CalendarDefinition|array<string, mixed> $definition)
 * @method static \Atannex\NwehMundani\Core\Kernel\CalendarContext context()
 * @method static CalendarManager withContext(\Atannex\NwehMundani\Core\Kernel\CalendarContext $context)
 * @method static CalendarManager forTenant(string|int|null $tenant)
 * @method static CalendarManager withLocale(?string $locale)
 * @method static CalendarManager withTimezone(string $timezone)
 * @method static \Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver extend(\Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver|string $driver, bool $replace = false)
 * @method static void extendRules(\Atannex\NwehMundani\Core\Rules\RuleHandler|string $handler, bool $replace = false)
 * @method static void addObservanceResolver(\Atannex\NwehMundani\Core\Observances\ObservanceResolver $resolver)
 * @method static void addLocalizationProvider(\Atannex\NwehMundani\Core\Localization\LocalizationProvider $provider)
 * @method static \Atannex\NwehMundani\Core\Drivers\DriverRegistry drivers()
 * @method static \Atannex\NwehMundani\Core\Rules\RuleRegistry rules()
 *
 * @see CalendarManager
 */
final class NwehMundani extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CalendarManager::class;
    }
}
