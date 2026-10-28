<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Kernel;

use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;

/**
 * Outcome of validating a definition. Errors make a definition unusable;
 * warnings flag things a human should look at (for example an assumption
 * nobody has verified yet) without blocking registration.
 */
final readonly class ValidationReport
{
    /**
     * @param  list<Violation>  $errors
     * @param  list<Violation>  $warnings
     */
    public function __construct(
        public string $subject,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function merge(self $other): self
    {
        return new self(
            $this->subject,
            [...$this->errors, ...$other->errors],
            [...$this->warnings, ...$other->warnings],
        );
    }

    /** @throws InvalidDefinition */
    public function throwIfFailed(): void
    {
        if (! $this->passes()) {
            throw new InvalidDefinition($this->subject, $this->errors);
        }
    }
}
