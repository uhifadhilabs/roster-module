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
 * THE PLANNING SHEET — people down, days across, per station.
 *
 * ONE READ FOR THE WHOLE WINDOW. Thirty-four rangers over twenty-eight
 * days is 952 cells, and asking per cell is how a planner's tab ends up
 * slower than the month it plans.
 *
 * ITS OWN FIGURES COME OFF ITS OWN CELLS. The band above the sheet counts
 * what the picture under it draws, so a reader can check one against the
 * other — a strip that counted a different window would be a strip
 * nobody could check at all.
 */
final readonly class Sheet
{
    /**
     * @param list<SheetBand> $bands
     */
    public function __construct(
        public SheetWindow $window,
        public array $bands,
        /** Every station on the area's books, whether anybody is stationed there or not. */
        public int $stations,
    ) {
    }

    public function rangers(): int
    {
        $rangers = 0;
        foreach ($this->bands as $band) {
            $rangers += $band->rangers();
        }

        return $rangers;
    }

    public function unfilled(): int
    {
        $unfilled = 0;
        foreach ($this->bands as $band) {
            $unfilled += $band->unfilled();
        }

        return $unfilled;
    }

    public function editedByHand(): int
    {
        $edited = 0;
        foreach ($this->bands as $band) {
            foreach ($band->rows as $row) {
                $edited += $row->editedByHand();
            }
        }

        return $edited;
    }

    /** How many watches the window actually holds — "days planned". */
    public function daysPlanned(): int
    {
        $planned = 0;
        foreach ($this->bands as $band) {
            foreach ($band->rows as $row) {
                foreach ($row->cells as $cell) {
                    if (SheetCellKind::Watch === $cell->kind) {
                        ++$planned;
                    }
                }
            }
        }

        return $planned;
    }

    public function isEmpty(): bool
    {
        return [] === $this->bands;
    }
}
