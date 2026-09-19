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
 * ONE WATCH IN ONE CELL OF THE WEEK GRID — what the post asked for, and how
 * many it got.
 *
 * EVERY HOLE IS DRAWN AS A HOLE, which is the one thing the week planner is
 * for: the grid is the only surface in the module where a missing watch is
 * visible before the day arrives. So the cell carries BOTH numbers and lets
 * the template say "N 1 of 2" rather than carrying a pre-baked string that
 * cannot be read any other way.
 */
final readonly class WeekCell
{
    public function __construct(
        public string $shiftKey,
        /** The short mark the grid prints — "D", "N", "O". */
        public string $initial,
        public int $filled,
        public int $expected,
    ) {
    }

    public function isShort(): bool
    {
        return $this->filled < $this->expected;
    }

    /** Nobody at all, on a watch that was asked for. The loudest cell there is. */
    public function isEmpty(): bool
    {
        return 0 === $this->filled;
    }

    public function shortfall(): int
    {
        return max(0, $this->expected - $this->filled);
    }
}
