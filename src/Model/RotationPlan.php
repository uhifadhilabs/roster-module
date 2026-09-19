<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Model;

use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Enum\RestRule;

/**
 * EVERYTHING THE RING NEEDS TO PRODUCE A MONTH, AND NOTHING ELSE.
 *
 * A rotation entity carries an area, a station, a pool of real people and a
 * horizon. None of those change what the ring SAYS — the ring says "on the
 * fourth day of the cycle, whoever stands here works a night" — so the
 * calculation takes this instead, and can be unit-tested with no kernel, no
 * database and no people.
 *
 * {@see fromRotation()} is the one place the two shapes meet.
 */
final readonly class RotationPlan
{
    /**
     * @param int                        $poolSize          how many people enter the ring; each one day later than the last
     * @param list<int>                  $standDownWeekdays ISO numbers, monday 1 to sunday 7
     * @param array<string, ShiftWindow> $windows           the area's shift vocabulary, keyed by shift key
     */
    public function __construct(
        public Cycle $cycle,
        public int $poolSize,
        public \DateTimeImmutable $anchoredOn,
        public array $standDownWeekdays = [],
        public RestRule $restRule = RestRule::None,
        public array $windows = [],
    ) {
    }

    /**
     * @param array<string, ShiftWindow> $windows the area's shift vocabulary
     */
    public static function fromRotation(Rotation $rotation, array $windows): self
    {
        return new self(
            $rotation->getCycle(),
            $rotation->getPool()->count(),
            $rotation->getAnchoredOn(),
            $rotation->getStandDownWeekdays(),
            $rotation->getRestRule(),
            $windows,
        );
    }

    public function standsDownOn(\DateTimeImmutable $day): bool
    {
        return \in_array((int) $day->format('N'), $this->standDownWeekdays, true);
    }

    /** Which day of the ring a calendar day is, counted from the anchor and wrapping both ways. */
    public function offsetFor(\DateTimeImmutable $day): int
    {
        $diff = $this->anchoredOn->setTime(0, 0)->diff($day->setTime(0, 0));

        return $diff->invert > 0 ? -$diff->days : $diff->days;
    }

    public function windowFor(string $shiftKey): ?ShiftWindow
    {
        return $this->windows[$shiftKey] ?? null;
    }
}
