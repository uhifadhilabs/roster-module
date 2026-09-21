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
 * WHAT ONE DAY OF ONE RANGER'S ROW IS. TWO ANSWERS, and that is the
 * ruling of 21 sep: a ranger is on a shift, or they are off. There is no
 * third thing a PERSON can be.
 *
 *   Watch      the calendar's own bar, tinted with the shift's own colour.
 *   Off        NOTHING IS DRAWN. Six marks came off every cell before
 *              anything was added, and the dashed box on every off day was
 *              one of them: a fortnight is mostly off days, and outlining
 *              them draws the quiet half of the sheet loudest.
 *
 * WHAT USED TO BE A THIRD KIND — "unfilled" — was a gap blamed on a
 * person for standing where a ring happened to point. A gap belongs to
 * the STATION on the DAY, and it is stated there:
 * {@see SheetCover} on the band's own head row.
 */
enum SheetCellKind: string
{
    case Watch = 'watch';
    case Off = 'off';

    /** The class the cell wears — `.cl` plus this. */
    public function className(): string
    {
        return match ($this) {
            self::Watch => 'bar',
            self::Off => 'off',
        };
    }
}
