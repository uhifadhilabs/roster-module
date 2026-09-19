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

/**
 * A NAMED SHIFT REDUCED TO THE TWO NUMBERS A CALCULATION NEEDS: when it opens
 * and when it closes, as minutes past midnight.
 *
 * The rest rules are arithmetic about the gap between one watch ending and
 * the next beginning, and arithmetic on "18:00" is arithmetic on a string.
 * This is the shape the planner thinks in; {@see \Uhifadhi\Roster\Entity\Shift}
 * is the shape the area edits.
 *
 * A WINDOW MAY CROSS MIDNIGHT, and then its end is on the FOLLOWING day —
 * which is the whole reason a night watch can collide with the next morning's
 * day watch and a day watch cannot collide with anything.
 */
final readonly class ShiftWindow
{
    private function __construct(
        public string $key,
        public int $startMinute,
        public int $endMinute,
    ) {
    }

    /**
     * @param string $startsAt HH:MM
     * @param string $endsAt   HH:MM
     *
     * @throws \InvalidArgumentException when either end is not a clock time
     */
    public static function of(string $key, string $startsAt, string $endsAt): self
    {
        return new self($key, self::minutes($key, 'start', $startsAt), self::minutes($key, 'end', $endsAt));
    }

    private static function minutes(string $key, string $which, string $clock): int
    {
        if (1 !== preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $clock, $parts)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" shift\'s %s is not an HH:MM clock time: "%s".', $key, $which, $clock));
        }

        return ((int) $parts[1] * 60) + (int) $parts[2];
    }

    /** Whether the window runs past midnight into the next calendar day. */
    public function crossesMidnight(): bool
    {
        return $this->endMinute <= $this->startMinute;
    }

    /** How long the watch is, in minutes. A midnight crossing is measured the long way round. */
    public function lengthMinutes(): int
    {
        return $this->crossesMidnight()
            ? (24 * 60) - $this->startMinute + $this->endMinute
            : $this->endMinute - $this->startMinute;
    }

    /** When the watch begins, in minutes from the midnight its day starts at. */
    public function startsAtMinuteOfDay(): int
    {
        return $this->startMinute;
    }

    /**
     * When the watch ends, in minutes from the midnight ITS DAY starts at —
     * so a night watch ending at 06:00 answers 1800, not 360. That is what
     * makes a gap between two watches on consecutive days a subtraction.
     */
    public function endsAtMinuteFromItsDay(): int
    {
        return $this->startMinute + $this->lengthMinutes();
    }
}
