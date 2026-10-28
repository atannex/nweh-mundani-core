<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Application\Data;

use Atannex\NwehMundani\Core\Governance\Source;

final readonly class SourceData extends Data
{
    public function __construct(public Source $source) {}

    public function toArray(): array
    {
        $data = $this->source->toArray();
        unset($data['tenant']);

        return $data;
    }
}
