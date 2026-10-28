<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests\Fixtures;

use Atannex\NwehMundani\Core\Contracts\CalendarDefinitionProvider;

/** Stands in for another Composer package contributing a calendar. */
final class ExtraCalendars implements CalendarDefinitionProvider
{
    public function calendars(): iterable
    {
        return [cyclicCalendar('five-day', 5, '2026-01-01')];
    }

    public function observances(): iterable
    {
        return [[
            'id' => 'market', 'calendar' => 'five-day', 'name' => ['original' => 'Market', 'language' => 'en', 'translations' => ['fr' => 'Marché']],
            'category' => 'market', 'verification' => 'verified',
            'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']]]],
        ]];
    }

    public function sources(): iterable
    {
        return [];
    }
}
