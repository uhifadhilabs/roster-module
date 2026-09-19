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
 * THE FIVE FIGURES ABOVE THE GRID.
 *
 * EVERY ONE OF THEM MEASURES THE FORTNIGHT THE GRID DRAWS. A strip that
 * counted a different window would be a strip nobody could check against the
 * picture under it — and the whole reason the figures sit above the grid is
 * that somebody can.
 */
final readonly class RotaFigures
{
    public function __construct(
        /** Watches the window holds, across everybody. */
        public int $personWatches,
        /** Watches the rings asked for and nobody is on. */
        public int $holes,
        /** The most nights any one person stands. */
        public int $heaviestNights,
        public ?string $heaviestName,
        /** Claims today that the positions disagree with. */
        public int $flaggedToday,
        /** Offers out and not yet answered. */
        public int $swapsPending,
    ) {
    }
}
