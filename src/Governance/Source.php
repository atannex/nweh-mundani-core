<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Governance;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Observances\Visibility;
use Atannex\NwehMundani\Core\Tenancy\TenantKey;
use InvalidArgumentException;

/**
 * Where a piece of calendar knowledge comes from.
 */
final readonly class Source
{
    public string $tenant;

    public function __construct(
        public string $id,
        public SourceKind $kind,
        public string $title,
        public ?string $citation = null,
        public ?string $custodian = null,
        public ?string $url = null,
        public ?CivilDate $recordedOn = null,
        public VerificationStatus $status = VerificationStatus::Submitted,
        public ?string $notes = null,
        ?string $tenant = null,
        public Visibility $visibility = Visibility::Public,
    ) {
        Identifier::assert($id, 'source identifier');
        $this->tenant = TenantKey::normalise($tenant);

        if (trim($title) === '') {
            throw new InvalidArgumentException("Source [{$id}] needs a title.");
        }

        if ($url !== null && ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException("Source [{$id}] url must be http(s).");
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $tenant = null): self
    {
        $string = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) ? $value : null;
        };

        return new self(
            id: $string('id') ?? throw new InvalidArgumentException('A source needs an [id].'),
            kind: SourceKind::tryFrom($string('kind') ?? '') ?? throw new InvalidArgumentException('Source kind must be one of: '.implode(', ', array_column(SourceKind::cases(), 'value')).'.'),
            title: $string('title') ?? throw new InvalidArgumentException('A source needs a [title].'),
            citation: $string('citation'),
            custodian: $string('custodian'),
            url: $string('url'),
            recordedOn: ($d = $string('recorded_on')) === null ? null : CivilDate::parse($d),
            status: VerificationStatus::tryFrom($string('status') ?? 'submitted') ?? throw new InvalidArgumentException('Unknown source status.'),
            notes: $string('notes'),
            tenant: $tenant ?? $string('tenant'),
            visibility: Visibility::tryFrom($string('visibility') ?? 'public') ?? throw new InvalidArgumentException('Source visibility must be public, restricted or private.'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant' => $this->tenant,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'citation' => $this->citation,
            'custodian' => $this->custodian,
            'url' => $this->url,
            'recorded_on' => $this->recordedOn?->toString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'visibility' => $this->visibility->value,
        ];
    }
}
