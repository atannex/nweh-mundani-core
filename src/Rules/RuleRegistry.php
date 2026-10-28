<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Rules;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarDefinition;
use Atannex\NwehMundani\Core\Domain\CalendarRule;
use Atannex\NwehMundani\Core\Domain\EffectiveRange;
use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Exceptions\InvalidDefinition;
use Atannex\NwehMundani\Core\Exceptions\UnknownRuleType;
use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;
use Atannex\NwehMundani\Core\Kernel\Violation;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Rule handlers by type, and the single entry point for turning raw rule
 * data into CalendarRule objects and evaluating them.
 */
final class RuleRegistry
{
    /** @var array<string, RuleHandler> */
    private array $handlers = [];

    public function __construct(private readonly ?Container $container = null) {}

    /** @param RuleHandler|class-string<RuleHandler> $handler */
    public function register(RuleHandler|string $handler, bool $replace = false): void
    {
        if (is_string($handler)) {
            if (! class_exists($handler) || ! is_subclass_of($handler, RuleHandler::class)) {
                throw UnsafeRegistration::because("[{$handler}] is not a class implementing ".RuleHandler::class.'.');
            }

            $handler = $this->container?->make($handler) ?? new $handler;
        }

        $type = $handler->type();

        if (! Identifier::isValid($type)) {
            throw UnsafeRegistration::because("rule type [{$type}] is not a valid identifier.");
        }

        if (isset($this->handlers[$type]) && ! $replace && $handler::class !== $this->handlers[$type]::class) {
            throw UnsafeRegistration::because("rule type [{$type}] is already handled by ".$this->handlers[$type]::class.'.');
        }

        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** @throws UnknownRuleType */
    public function handler(string $type): RuleHandler
    {
        return $this->handlers[$type] ?? throw UnknownRuleType::forType($type);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @param  array<string, mixed>  $data  {type, parameters, effective?: {from, until}, priority?}
     *
     * @throws InvalidDefinition|UnknownRuleType
     */
    public function parse(array $data, string $subject = 'rule'): CalendarRule
    {
        $type = $data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw InvalidDefinition::single($subject, 'type', 'A rule needs a [type].');
        }

        $handler = $this->handler($type);
        $parameters = $data['parameters'] ?? [];

        if (! is_array($parameters)) {
            throw InvalidDefinition::single($subject, 'parameters', 'Rule parameters must be an object.');
        }

        /** @var array<string, mixed> $parameters */
        $specification = $handler->parse($parameters);

        if (is_array($specification)) {
            throw new InvalidDefinition($subject, $specification);
        }

        try {
            $effective = self::range($data['effective'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw InvalidDefinition::single($subject, 'effective', $e->getMessage());
        }

        $priority = $data['priority'] ?? 0;

        if (! is_int($priority)) {
            throw InvalidDefinition::single($subject, 'priority', 'Rule priority must be an integer.');
        }

        return new CalendarRule($type, $specification, $effective, $priority);
    }

    public function matches(CalendarRule $rule, RuleContext $context): bool
    {
        return $rule->effective->contains($context->date)
            && $this->handler($rule->type)->matches($rule->specification, $context);
    }

    /** @return list<Violation> */
    public function validateAgainst(CalendarRule $rule, CalendarDefinition $definition): array
    {
        return $this->handler($rule->type)->validateAgainst($rule->specification, $definition);
    }

    public static function range(mixed $value): EffectiveRange
    {
        if ($value === null) {
            return new EffectiveRange;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('An effective range must be {from, until}.');
        }

        $bound = static function (mixed $v): ?CivilDate {
            if ($v === null) {
                return null;
            }

            if (! is_string($v)) {
                throw new InvalidArgumentException('Effective range bounds must be YYYY-MM-DD strings or null.');
            }

            return CivilDate::parse($v);
        };

        return new EffectiveRange($bound($value['from'] ?? null), $bound($value['until'] ?? null));
    }
}
