<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Tests\Fixtures;

use Atannex\NwehMundani\Core\Application\CalendarQueryService;
use Atannex\NwehMundani\Core\Exceptions\NwehMundaniException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a frontend controller looks like: validate transport input, call the
 * query service, render the result. No calendar maths here.
 */
final class CalendarController
{
    public function __construct(private readonly CalendarQueryService $calendars) {}

    public function month(Request $request, string $calendar, int $year, int $month): View
    {
        return view('fixtures::month', ['month' => $this->calendars->month($calendar, $year, $month)]);
    }

    public function api(Request $request, string $calendar, int $year, int $month): JsonResponse
    {
        try {
            return new JsonResponse($this->calendars->month($calendar, $year, $month));
        } catch (NwehMundaniException $e) {
            return new JsonResponse($e->toArray(), $e->errorKey() === 'calendar-not-found' ? 404 : 422);
        }
    }
}
