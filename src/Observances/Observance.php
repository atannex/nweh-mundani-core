<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\CalendarRule;
use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use InvalidArgumentException;

/**
 * Something that happens on certain days of a calendar: a market, festival,
 * rest day… It is attached to a calendar by identifier and to days by rules
 * (it occurs when any rule matches), so it is independent of the driver.
 */
final readonly class Observance
{
    public string $tenant;

    /** @var array<string, ObservanceOverride> keyed by original date */
    private array $overridesByDate;

    /**
     * @param  list<CalendarRule>  $rules
     * @param  list<string>  $sources
     * @param  list<ObservanceOverride>  $overrides
     */
    public function __construct(
        public string $id,
        public string $calendar,
        public Term $name,
        public string $category,
        public array $rules,
        public ?Term $description = null,
        public Visibility $visibility = Visibility::Public,
        public VerificationStatus $verification = VerificationStatus::Draft,
        public array $sources = [],
        public int $priority = 0,
        ?string $tenant = null,
        public ObservanceStatus $status = ObservanceStatus::Published,
        public array $overrides = [],
    ) {
        Identifier::assert($id, 'observance identifier');
        Identifier::assert($calendar, 'calendar identifier');
        Identifier::assert($category, 'observance category');
        $this->tenant = TenantKey::normalise($tenant);

        if ($rules === []) {
            throw new InvalidArgumentException("Observance [{$id}] needs at least one rule.");
        }

        $byDate = [];

        foreach ($overrides as $override) {
            $key = $override->date->toString();

            if (isset($byDate[$key])) {
                throw new InvalidArgumentException("Observance [{$id}] has two overrides for {$key}.");
            }

            $byDate[$key] = $override;
        }

        $this->overridesByDate = $byDate;
    }

    public function overrideOn(CivilDate $date): ?ObservanceOverride
    {
        return $this->overridesByDate[$date->toString()] ?? null;
    }

    /** @return list<ObservanceOverride> Reschedules whose target falls on $date. */
    public function rescheduledOnto(CivilDate $date): array
    {
        return array_values(array_filter($this->overrides, static fn (ObservanceOverride $o): bool => $o->rescheduledTo !== null && $o->rescheduledTo->equals($date)));
    }

    public function isPublished(): bool
    {
        return $this->status === ObservanceStatus::Published;
    }

    /**
     * A copy with some fields replaced (observances are immutable).
     *
     * @param  list<ObservanceOverride>|null  $overrides
     */
    public function with(?ObservanceStatus $status = null, ?array $overrides = null): self
    {
        return new self($this->id, $this->calendar, $this->name, $this->category, $this->rules, $this->description, $this->visibility, $this->verification, $this->sources, $this->priority, $this->tenant, $status ?? $this->status, $overrides ?? $this->overrides);
    }

    public function isGlobal(): bool
    {
        return $this->tenant === TenantKey::GLOBAL;
    }

    public function categoryEnum(): ?ObservanceCategory
    {
        return ObservanceCategory::tryFrom($this->category);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant' => $this->tenant,
            'calendar' => $this->calendar,
            'name' => $this->name->toArray(),
            'category' => $this->category,
            'description' => $this->description?->toArray(),
            'visibility' => $this->visibility->value,
            'verification' => $this->verification->value,
            'sources' => $this->sources,
            'priority' => $this->priority,
            'status' => $this->status->value,
            'overrides' => array_map(static fn (ObservanceOverride $o): array => $o->toArray(), $this->overrides),
            'rules' => array_map(static fn (CalendarRule $r): array => $r->toArray(), $this->rules),
        ];
    }
}
