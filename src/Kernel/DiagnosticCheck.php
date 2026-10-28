<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

final readonly class DiagnosticCheck
{
    public function __construct(
        public string $name,
        public DiagnosticStatus $status,
        public string $message,
    ) {}
}
