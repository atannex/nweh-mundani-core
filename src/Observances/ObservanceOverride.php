<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Observances;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use InvalidArgumentException;

/**
 * A change to one scheduled occurrence of a recurring observance. The rule
 * stays as it is; only the occurrence on $date is cancelled or moved.
 * Occurrences themselves are never stored: they are computed from rules,
 * and overrides are the only per-date records.
 */
final readonly class ObservanceOverride
{
    public function __construct(
        public CivilDate $date,
        public OverrideAction $action,
        public ?CivilDate $rescheduledTo = null,
        public ?string $reason = null,
    ) {
        if ($action === OverrideAction::Reschedule && ($rescheduledTo === null || $rescheduledTo->equals($date))) {
            throw new InvalidArgumentException("Rescheduling the {$date} occurrence needs a different target date.");
        }

        if ($action === OverrideAction::Cancel && $rescheduledTo !== null) {
            throw new InvalidArgumentException('A cancellation has no target date.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $date = $data['date'] ?? null;
        $to = $data['rescheduled_to'] ?? null;
        $reason = $data['reason'] ?? null;
        $action = OverrideAction::tryFrom(is_string($data['action'] ?? null) ? $data['action'] : '')
            ?? throw new InvalidArgumentException('Override action must be cancel or reschedule.');

        return new self(
            is_string($date) ? CivilDate::parse($date) : throw new InvalidArgumentException('Override needs a [date].'),
            $action,
            is_string($to) ? CivilDate::parse($to) : null,
            is_string($reason) ? $reason : null,
        );
    }

    /** @return array{date: string, action: string, rescheduled_to: ?string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toString(),
            'action' => $this->action->value,
            'rescheduled_to' => $this->rescheduledTo?->toString(),
            'reason' => $this->reason,
        ];
    }
}
