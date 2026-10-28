<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Localization\Term;

/**
 * The name of one unit value of a calendar, e.g. a month. The identifier is
 * the driver's technical key for that value ("m1", "adar-ii"); the label is
 * the culturally authoritative term. Drivers never carry names themselves.
 */
final readonly class CalendarName
{
    public function __construct(
        public string $id,
        public Term $label,
    ) {
        Identifier::assert($id, 'name identifier');
    }

    /** @return array{id: string, label: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label->toArray()];
    }
}
