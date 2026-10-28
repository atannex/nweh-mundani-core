<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

use Atannex\NwehMundani\Core\Kernel\CalendarContext;

/**
 * A localization provider that needs to know whose data it serves, such as
 * the database-backed TranslationCatalog. It is only asked when a context
 * is available, so it can never answer one tenant with another's text.
 */
interface ContextualLocalizationProvider
{
    public function translateFor(CalendarContext $context, Term $term, string $key, string $locale): ?string;
}
