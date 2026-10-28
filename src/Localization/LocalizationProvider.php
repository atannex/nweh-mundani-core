<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

/**
 * Supplies renderings that are not stored on the term itself, for example
 * from an application's lang files or a translation service.
 *
 * $key identifies what the term names (calendar.nweh.cycle.traditional-days.position.ankoah)
 * so providers never have to key translations on the translated text.
 */
interface LocalizationProvider
{
    public function translate(Term $term, string $key, string $locale): ?string;
}
