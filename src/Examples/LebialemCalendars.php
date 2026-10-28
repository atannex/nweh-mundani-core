<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Examples;

use Atannex\NwehMundani\Core\Contracts\CalendarDefinitionProvider;

/**
 * The bundled reference datasets (Nweh, Mundae (Wabane), Mmouck, Mmock),
 * read from resources/definitions/lebialem.php. Registered automatically
 * when config('nweh-mundani.examples.register') is true, and seeded into
 * the database by `php artisan nweh-mundani:seed-examples`.
 */
final class LebialemCalendars implements CalendarDefinitionProvider
{
    public const array IDS = ['nweh', 'mundae-wabane', 'mmouck', 'mmock'];

    /** @var array{sources: list<array<string, mixed>>, calendars: list<array<string, mixed>>}|null */
    private ?array $data = null;

    public function calendars(): iterable
    {
        return $this->data()['calendars'];
    }

    public function observances(): iterable
    {
        return [];
    }

    public function sources(): iterable
    {
        return $this->data()['sources'];
    }

    /** @return array{sources: list<array<string, mixed>>, calendars: list<array<string, mixed>>} */
    private function data(): array
    {
        /** @var array{sources: list<array<string, mixed>>, calendars: list<array<string, mixed>>} */
        return $this->data ??= require dirname(__DIR__, 2).'/resources/definitions/lebialem.php';
    }
}
