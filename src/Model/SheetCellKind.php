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
 * WHAT ONE DAY OF ONE RANGER'S ROW IS. Three answers, and the design draws
 * each of them differently on purpose:
 *
 *   Watch      the calendar's own bar, tinted with the shift's own colour.
 *   Off        NOTHING IS DRAWN. Six marks came off every cell before
 *              anything was added, and the dashed box on every off day was
 *              one of them: a fortnight is mostly off days, and outlining
 *              them draws the quiet half of the sheet loudest.
 *   Unfilled   THE ONLY OUTLINED THING on the sheet, in the alarm ink. A
 *              gap always shows at once, which is the whole reason this
 *              tab exists.
 */
enum SheetCellKind: string
{
    case Watch = 'watch';
    case Off = 'off';
    case Unfilled = 'unfilled';

    /** The class the cell wears — `.cl` plus this. */
    public function className(): string
    {
        return match ($this) {
            self::Watch => 'bar',
            self::Off => 'off',
            self::Unfilled => 'unf',
        };
    }
}
