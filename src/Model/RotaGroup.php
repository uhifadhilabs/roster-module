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
 * ONE POST AND THE PEOPLE ITS RING DRAWS FROM.
 *
 * A GROUP WITH NO PEOPLE IS STILL A GROUP. A post on the roster's books
 * whose ring draws from nobody is drawn with its heading and no rows —
 * because "this post is on the books and has nobody" is exactly the fact a
 * planner needs, and a group quietly omitted would read as a post that was
 * never added.
 */
final readonly class RotaGroup
{
    /**
     * @param list<RotaRow> $rows the post's people, in the order they enter the ring
     */
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public ?string $stationCode,
        /** What the watch asks for, in words: "day 2 · night 1". */
        public string $asks,
        public array $rows,
    ) {
    }
}
