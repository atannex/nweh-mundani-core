<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Atannex\NwehMundani\Core\Exceptions\UnauthorizedModification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether an actor may change calendar data. Bind your own to use
 * any roles/permissions system; the default delegates to Laravel's Gate.
 */
interface CalendarAuthorizer
{
    public const string MANAGE_CALENDARS = 'nweh-mundani.manage-calendars';

    public const string PUBLISH_CALENDARS = 'nweh-mundani.publish-calendars';

    public const string MANAGE_OBSERVANCES = 'nweh-mundani.manage-observances';

    public const string MANAGE_SOURCES = 'nweh-mundani.manage-sources';

    public const string VERIFY = 'nweh-mundani.verify';

    public const string REVIEW_CALENDARS = 'nweh-mundani.review-calendars';

    public const string ARCHIVE_CALENDARS = 'nweh-mundani.archive-calendars';

    public const string MANAGE_TRANSLATIONS = 'nweh-mundani.manage-translations';

    /** Read side: see actor names in history and audit records. */
    public const string VIEW_AUDIT = 'nweh-mundani.view-audit';

    /** Read side: run installation diagnostics (exposes class names and configuration). */
    public const string VIEW_DIAGNOSTICS = 'nweh-mundani.view-diagnostics';

    /** @throws UnauthorizedModification */
    public function authorize(string $ability, Authenticatable|SystemActor|null $actor, string $tenant, string $subject): void;

    /** The same decision without throwing (for read-side filtering). */
    public function allows(string $ability, Authenticatable|SystemActor|null $actor, string $tenant, string $subject): bool;
}
