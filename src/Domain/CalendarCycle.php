<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Domain;

use Atannex\NwehMundani\Core\Dates\CivilDate;
use Atannex\NwehMundani\Core\Exceptions\ConflictingAnchors;
use Atannex\NwehMundani\Core\Localization\Term;
use InvalidArgumentException;

/**
 * A repeating sequence of named positions of any positive length.
 *
 * Several cycles can run side by side in one calendar, each with its own
 * anchors and overrides. Nothing here knows about any particular length.
 */
final readonly class CalendarCycle
{
    /** @var array<string, CyclePosition> */
    private array $byId;

    /** @var array<string, CalendarException> keyed by date */
    private array $exceptionsByDate;

    /**
     * @param  list<CyclePosition>  $positions
     * @param  list<CalendarAnchor>  $anchors
     * @param  list<CalendarException>  $exceptions
     */
    public function __construct(
        public string $id,
        public Term $name,
        public array $positions,
        public array $anchors,
        public array $exceptions = [],
        public EffectiveRange $effective = new EffectiveRange,
        public CycleContinuity $continuity = CycleContinuity::Assumed,
    ) {
        Identifier::assert($id, 'cycle identifier');

        if ($positions === []) {
            throw new InvalidArgumentException("Cycle [{$id}] needs at least one position.");
        }

        if ($anchors === []) {
            throw new InvalidArgumentException("Cycle [{$id}] needs at least one anchor.");
        }

        $byId = [];

        foreach ($positions as $index => $position) {
            if ($position->ordinal !== $index + 1) {
                throw new InvalidArgumentException("Cycle [{$id}] position [{$position->id}] has ordinal {$position->ordinal}; expected ".($index + 1).'.');
            }

            if (isset($byId[$position->id])) {
                throw new InvalidArgumentException("Cycle [{$id}] lists position [{$position->id}] twice.");
            }

            $byId[$position->id] = $position;
        }

        $this->byId = $byId;

        $byDate = [];

        foreach ($exceptions as $exception) {
            $key = $exception->date->toString();

            if (isset($byDate[$key])) {
                throw new InvalidArgumentException("Cycle [{$id}] has two overrides for {$key}.");
            }

            $byDate[$key] = $exception;
        }

        $this->exceptionsByDate = $byDate;
    }

    public function length(): int
    {
        return count($this->positions);
    }

    public function position(string $id): ?CyclePosition
    {
        return $this->byId[$id] ?? null;
    }

    public function positionAt(int $index): CyclePosition
    {
        return $this->positions[self::mod($index, $this->length())];
    }

    /** Find a position by identifier, original spelling or any alternate spelling. */
    public function findPosition(string $query): ?CyclePosition
    {
        if (isset($this->byId[$query])) {
            return $this->byId[$query];
        }

        foreach ($this->positions as $position) {
            if ($position->label->matches($query)) {
                return $position;
            }
        }

        return null;
    }

    public function exceptionOn(CivilDate $date): ?CalendarException
    {
        return $this->exceptionsByDate[$date->toString()] ?? null;
    }

    /**
     * The anchor that governs a date, or null when no anchor's range covers it.
     *
     * When several anchors cover the date they must agree on its position;
     * if they do the earliest-dated one is used (ties by identifier), and if
     * they do not the engine refuses to choose.
     *
     * @throws ConflictingAnchors
     */
    public function anchorFor(CivilDate $date): ?CalendarAnchor
    {
        $candidates = array_values(array_filter($this->anchors, static fn (CalendarAnchor $a): bool => $a->effective->contains($date)));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (CalendarAnchor $a, CalendarAnchor $b): int => [$a->date->dayNumber, $a->id] <=> [$b->date->dayNumber, $b->id]);

        $indices = array_unique(array_map(fn (CalendarAnchor $a): int => $this->indexFrom($a, $date), $candidates));

        if (count($indices) > 1) {
            throw ConflictingAnchors::at($this->id, $date->toString(), array_map(static fn (CalendarAnchor $a): string => $a->id, $candidates));
        }

        return $candidates[0];
    }

    /** Signed day offset of $date from the cycle's position-one day implied by the anchor. */
    public function offsetFrom(CalendarAnchor $anchor, CivilDate $date): int
    {
        $position = $this->position($anchor->positionId)
            ?? throw new InvalidArgumentException("Anchor [{$anchor->id}] names unknown position [{$anchor->positionId}].");

        return $anchor->date->daysUntil($date) + $position->ordinal - 1;
    }

    /** Zero-based position index of $date under an anchor. */
    public function indexFrom(CalendarAnchor $anchor, CivilDate $date): int
    {
        return self::mod($this->offsetFrom($anchor, $date), $this->length());
    }

    /** Whether two anchors put every date at the same position. */
    public function anchorsAgree(CalendarAnchor $a, CalendarAnchor $b): bool
    {
        return $this->indexFrom($a, $b->date) === $this->indexFrom($b, $b->date);
    }

    /** Mathematical modulo: always in [0, $length). */
    public static function mod(int $value, int $length): int
    {
        return (($value % $length) + $length) % $length;
    }

    /**
     * Floor division, exact for negative values.
     */
    public static function floorDiv(int $value, int $divisor): int
    {
        return intdiv($value - self::mod($value, $divisor), $divisor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name->toArray(),
            'length' => $this->length(),
            'continuity' => $this->continuity->value,
            'effective' => $this->effective->toArray(),
            'positions' => array_map(static fn (CyclePosition $p): array => $p->toArray(), $this->positions),
            'anchors' => array_map(static fn (CalendarAnchor $a): array => $a->toArray(), $this->anchors),
            'exceptions' => array_map(static fn (CalendarException $e): array => $e->toArray(), $this->exceptions),
        ];
    }
}
