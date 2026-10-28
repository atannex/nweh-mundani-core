<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Examples;

use Atannex\NwehMundani\Core\Contracts\CalendarDefinitionProvider;

/**
 * Optional Gregorian, Julian, tabular Hijri and Hebrew definitions for the
 * arithmetic drivers (resources/definitions/reference.php). Registered when
 * config('nweh-mundani.examples.reference') is true.
 */
final class ReferenceCalendars implements CalendarDefinitionProvider
{
    public const array IDS = ['gregorian', 'julian', 'hijri-tabular', 'hebrew'];

    public function calendars(): iterable
    {
        /** @var array{calendars: list<array<string, mixed>>} $data */
        $data = require dirname(__DIR__, 2).'/resources/definitions/reference.php';

        return $data['calendars'];
    }

    public function observances(): iterable
    {
        return [];
    }

    public function sources(): iterable
    {
        return [];
    }
}
