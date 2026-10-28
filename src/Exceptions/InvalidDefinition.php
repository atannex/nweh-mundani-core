<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Exceptions;

use Atannex\NwehMundani\Core\Kernel\Violation;

/**
 * A definition, rule or observance failed validation. Carries every
 * violation found, not only the first, so authors can fix them in one pass.
 */
final class InvalidDefinition extends NwehMundaniException
{
    /** @param list<Violation> $violations */
    public function __construct(string $subject, public readonly array $violations)
    {
        $lines = array_map(static fn (Violation $v): string => "  - {$v}", $violations);

        parent::__construct("Invalid definition [{$subject}]:\n".implode("\n", $lines));
    }

    /** @return array{error: string, message: string, violations: list<array{path: string, message: string}>} */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'violations' => array_map(static fn (Violation $v): array => ['path' => $v->path, 'message' => $v->message], $this->violations),
        ];
    }

    public static function single(string $subject, string $path, string $message): self
    {
        return new self($subject, [new Violation($path, $message)]);
    }
}
