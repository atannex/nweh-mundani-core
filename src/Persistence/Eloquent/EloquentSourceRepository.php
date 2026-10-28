<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Governance\Source;
use Atannex\NwehMundani\Core\Persistence\Contracts\SourceRepository;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\SourceRecord;
use Throwable;

final class EloquentSourceRepository implements SourceRepository
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = (new SourceRecord)->getConnection()->getSchemaBuilder()->hasTable('nmc_sources');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function find(string $tenant, string $id): ?Source
    {
        $row = SourceRecord::query()->where('tenant_key', $tenant)->where('identifier', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(string $tenant): array
    {
        return array_values(SourceRecord::query()->where('tenant_key', $tenant)->orderBy('identifier')->get()->map(fn (SourceRecord $row): Source => $this->hydrate($row))->all());
    }

    public function save(Source $source): void
    {
        $data = $source->toArray();

        SourceRecord::query()->updateOrCreate(['tenant_key' => $source->tenant, 'identifier' => $source->id], [
            'kind' => $data['kind'],
            'title' => $data['title'],
            'citation' => $data['citation'],
            'custodian' => $data['custodian'],
            'url' => $data['url'],
            'recorded_on' => $data['recorded_on'],
            'status' => $data['status'],
            'notes' => $data['notes'],
            'visibility' => $data['visibility'],
        ]);
    }

    private function hydrate(SourceRecord $row): Source
    {
        return Source::fromArray([
            'id' => $row->identifier,
            'kind' => $row->kind,
            'title' => $row->title,
            'citation' => $row->citation,
            'custodian' => $row->custodian,
            'url' => $row->url,
            'recorded_on' => EloquentCalendarRepository::day($row->recorded_on),
            'status' => $row->status,
            'notes' => $row->notes,
            'visibility' => $row->visibility,
        ], $row->tenant_key);
    }
}
