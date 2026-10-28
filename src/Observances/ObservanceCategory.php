<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

/**
 * Built-in observance categories. Categories are stored as identifiers, so
 * applications may use their own (any valid identifier) alongside these.
 */
enum ObservanceCategory: string
{
    case Market = 'market';
    case Festival = 'festival';
    case Ceremony = 'ceremony';
    case Rest = 'rest';
    case Religious = 'religious';
    case Agricultural = 'agricultural';
    case Meeting = 'meeting';
    case Custom = 'custom';
}
