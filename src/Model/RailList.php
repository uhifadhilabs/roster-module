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
 * ONE LIST IN THE RAIL, WITH ITS OWN HEAD AND ITS OWN COUNT.
 *
 * A LIST STATES ITS OWN COUNT IN ITS HEAD, because the rail carries
 * several and a reader scrolling the third one has lost sight of how long
 * the first was. The count is of the WHOLE list, not of the rows a
 * sub-head happens to hold.
 *
 * THE SUB-HEADS ARE PART OF THE LIST, not a second structure over it: "with
 * a watch · 6" and "no watch in this module · 6" are one list read in the
 * order that answers the question, and splitting them into two widgets
 * would make the second look optional.
 */
final readonly class RailList
{
    /**
     * @param list<array{label: string, rows: list<RailRow>}> $sections each sub-head and what sits under it
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $sections,
    ) {
    }

    /** Every row in the list, whichever sub-head it sits under. */
    public function count(): int
    {
        $total = 0;
        foreach ($this->sections as $section) {
            $total += \count($section['rows']);
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count();
    }
}
