<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules;

/**
 * Parsed, validated parameters of one rule. Each handler defines its own
 * typed specification; toArray() gives the canonical stored form.
 */
interface RuleSpecification
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
