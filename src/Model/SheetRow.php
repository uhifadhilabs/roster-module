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
 * ONE RANGER ACROSS THE WINDOW — the sheet is people down, so this is a
 * line of it.
 *
 * THE SEAT IS PART OF THE ROW. A pattern staffs a station by giving each
 * person the same ring one day later than the last, so which day of the
 * ring a ranger is on depends on where they stand at their station. The
 * seat is that place, and it is the row's because a cell that had to
 * recount it would recount it fourteen times.
 */
final readonly class SheetRow
{
    /**
     * @param list<SheetCell> $cells one per day of the window, in order
     */
    public function __construct(
        public string $personUuid,
        public string $personName,
        public ?string $stationCode,
        public int $seat,
        public array $cells,
    ) {
    }

    public function editedByHand(): int
    {
        $edited = 0;
        foreach ($this->cells as $cell) {
            if ($cell->editedByHand) {
                ++$edited;
            }
        }

        return $edited;
    }
}
