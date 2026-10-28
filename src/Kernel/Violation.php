<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Stringable;

/**
 * One problem found while validating a definition: where it is and what is wrong.
 */
final readonly class Violation implements Stringable
{
    public function __construct(
        public string $path,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return $this->path === '' ? $this->message : "{$this->path}: {$this->message}";
    }
}
