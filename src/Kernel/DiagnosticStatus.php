<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

enum DiagnosticStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';
}
