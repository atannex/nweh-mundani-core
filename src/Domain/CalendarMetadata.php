<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Localization\Term;
use InvalidArgumentException;

/**
 * Descriptive, non-computational facts about a calendar.
 *
 * $extra is the one open-ended part, and it is bounded: at most
 * MAX_EXTRA_KEYS slug keys with scalar values.
 */
final readonly class CalendarMetadata
{
    public const int MAX_EXTRA_KEYS = 50;

    /**
     * @param  list<Assumption>  $assumptions
     * @param  list<string>  $sources  Source identifiers for the definition as a whole.
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $extra  Validated here: slug keys, scalar or null values.
     */
    public function __construct(
        public ?Term $description = null,
        public ?string $region = null,
        public VerificationStatus $verification = VerificationStatus::Draft,
        public array $assumptions = [],
        public array $sources = [],
        public array $tags = [],
        public array $extra = [],
    ) {
        if (count($extra) > self::MAX_EXTRA_KEYS) {
            throw new InvalidArgumentException('Calendar metadata [extra] allows at most '.self::MAX_EXTRA_KEYS.' keys.');
        }

        foreach ($extra as $key => $value) {
            Identifier::assert((string) $key, 'metadata key');

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Calendar metadata [extra.{$key}] must be a scalar.");
            }
        }
    }

    public function assumption(string $id): ?Assumption
    {
        foreach ($this->assumptions as $assumption) {
            if ($assumption->id === $id) {
                return $assumption;
            }
        }

        return null;
    }

    /** @return list<Assumption> */
    public function unverifiedAssumptions(): array
    {
        return array_values(array_filter($this->assumptions, static fn (Assumption $a): bool => $a->status !== VerificationStatus::Verified));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $extra = $this->extra;
        ksort($extra);

        return [
            'description' => $this->description?->toArray(),
            'region' => $this->region,
            'verification' => $this->verification->value,
            'assumptions' => array_map(static fn (Assumption $a): array => $a->toArray(), $this->assumptions),
            'sources' => $this->sources,
            'tags' => $this->tags,
            'extra' => $extra,
        ];
    }
}
