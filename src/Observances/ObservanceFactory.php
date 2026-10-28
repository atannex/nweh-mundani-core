<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\UnknownRuleType;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Kernel\Violation;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Rules\RuleRegistry;
use InvalidArgumentException;

/**
 * Turns raw observance data (config arrays, database rows) into validated
 * Observance objects, parsing each rule through the rule registry.
 */
final readonly class ObservanceFactory
{
    public function __construct(private RuleRegistry $rules) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidDefinition|UnknownRuleType
     */
    public function fromArray(array $data, ?string $tenant = null): Observance
    {
        $subject = 'observance '.(is_string($data['id'] ?? null) ? $data['id'] : '?');
        $rules = [];

        $rawRules = $data['rules'] ?? [];

        if (! is_array($rawRules) || ! array_is_list($rawRules)) {
            throw InvalidDefinition::single($subject, 'rules', 'must be a list of rules.');
        }

        foreach ($rawRules as $i => $rule) {
            if (! is_array($rule)) {
                throw InvalidDefinition::single($subject, "rules.{$i}", 'must be an object.');
            }

            /** @var array<string, mixed> $rule */
            $rules[] = $this->rules->parse($rule, "{$subject} rules.{$i}");
        }

        try {
            $name = $data['name'] ?? null;
            $description = $data['description'] ?? null;
            $sources = $data['sources'] ?? [];
            $priority = $data['priority'] ?? 0;
            $overrides = $data['overrides'] ?? [];

            if (! is_array($overrides) || ! array_is_list($overrides)) {
                throw new InvalidArgumentException('[overrides] must be a list.');
            }

            return new Observance(
                id: self::string($data, 'id'),
                calendar: self::string($data, 'calendar'),
                name: is_string($name) || is_array($name) ? Term::fromValue($name) : throw new InvalidArgumentException('[name] is required.'),
                category: self::string($data, 'category'),
                rules: $rules,
                description: is_string($description) || is_array($description) ? Term::fromValue($description) : null,
                visibility: Visibility::tryFrom(is_string($data['visibility'] ?? null) ? $data['visibility'] : 'public') ?? throw new InvalidArgumentException('[visibility] must be public, restricted or private.'),
                verification: VerificationStatus::tryFrom(is_string($data['verification'] ?? null) ? $data['verification'] : 'draft') ?? throw new InvalidArgumentException('[verification] is not a verification status.'),
                sources: is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [],
                priority: is_int($priority) ? $priority : throw new InvalidArgumentException('[priority] must be an integer.'),
                tenant: $tenant ?? (is_string($data['tenant'] ?? null) ? $data['tenant'] : null),
                status: ObservanceStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : 'published') ?? throw new InvalidArgumentException('[status] must be draft, published or cancelled.'),
                overrides: array_map(static fn (mixed $o): ObservanceOverride => is_array($o) ? ObservanceOverride::fromArray($o) : throw new InvalidArgumentException('Each override must be an object.'), $overrides),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidDefinition($subject, [new Violation('', $e->getMessage())]);
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException("[{$key}] is required.");
    }
}
