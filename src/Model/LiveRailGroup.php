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

use Uhifadhi\Contracts\Area\LivePosition;

/**
 * ONE HEADING IN THE RAIL BESIDE THE PLATE, and the people under it.
 *
 * THE RAIL LISTS EVERYBODY AND THE MAP DRAWS THE ONES WITH A FIX. That is
 * the whole reason the rail exists: a ranger at their post whose phone has
 * said nothing is on this list and nowhere on that map, and a surface that
 * showed only the map would report them as absent. The last group is
 * exactly those people, and it is last because it is the one somebody acts
 * on.
 *
 * THE TONE IS THE HOUSE'S. A module has no hue; these are the same three
 * semantic classes every chip in the product wears.
 */
final readonly class LiveRailGroup
{
    /**
     * @param list<array{person: RosteredPerson, post: PostPresence, fix: LivePosition|null, age: string|null, stale: bool}> $rows
     */
    public function __construct(
        public string $label,
        /** A house state class, or empty where the group states no verdict. */
        public string $tone,
        public array $rows,
        /**
         * WHETHER THIS GROUP IS THE TAIL OF THE LIST — people who are
         * legitimately not on the watch. The surface folds the tail behind
         * one head and still counts it, because "not here" and "not due"
         * are different answers and only one of them is a problem.
         */
        public bool $tail = false,
    ) {
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }
}
