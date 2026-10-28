<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Facades\NwehMundani;
use Atannex\NwehMundani\Core\Observances\ObservanceFactory;
use Atannex\NwehMundani\Core\Observances\ObservanceGate;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    NwehMundani::register(cyclicCalendar('town'));
    $factory = app(ObservanceFactory::class);
    $repo = app('nweh-mundani.static-observances');

    foreach (['public', 'restricted', 'private'] as $visibility) {
        $repo->save($factory->fromArray([
            'id' => "{$visibility}-day", 'calendar' => 'town', 'name' => ucfirst($visibility), 'category' => 'ceremony',
            'visibility' => $visibility, 'priority' => $visibility === 'public' ? 10 : 0,
            'rules' => [['type' => 'cycle-position', 'parameters' => ['cycle' => 'days', 'positions' => ['p1']]]],
        ]));
    }
});

function visibleIds(?User $viewer = null): array
{
    return array_map(fn ($o) => $o->id, NwehMundani::calendar('town')->observancesOn('2026-10-01', $viewer));
}

it('shows only public observances to anonymous viewers', function () {
    expect(visibleIds())->toBe(['public-day']);
});

it('denies restricted observances when the ability is not defined', function () {
    expect(visibleIds(new User))->toBe(['public-day']);
});

it('shows restricted and private observances only when the gate allows', function () {
    $allowed = (new User)->forceFill(['id' => 1]);
    $denied = (new User)->forceFill(['id' => 2]);
    Gate::define(ObservanceGate::VIEW_RESTRICTED, fn (User $user) => $user->id === 1);
    Gate::define(ObservanceGate::VIEW_PRIVATE, fn (User $user) => $user->id === 1);

    expect(visibleIds($allowed))->toBe(['public-day', 'private-day', 'restricted-day'])
        ->and(visibleIds($denied))->toBe(['public-day']);
});

it('orders matches by priority then identifier', function () {
    Gate::define(ObservanceGate::VIEW_RESTRICTED, fn () => true);

    expect(visibleIds(new User))->toBe(['public-day', 'restricted-day']);
});
