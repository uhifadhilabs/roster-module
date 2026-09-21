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
 * ONE RANGER'S ONE DAY, on the planning sheet.
 *
 * A SHIFT'S COLOUR IS THE SHIFT'S, RULED 21 sep: the cell carries the
 * stored slot the shift was given when it was created, so the same watch
 * is the same colour on the sheet, on the calendar, on the Watches card
 * and on a pattern's cycle strip. It is never decided from a position in
 * a list, which is how a reordered list recolours a fortnight.
 *
 * AND IT CARRIES ITS DUTY'S UUID, because every item on the by-hand menu
 * acts on a duty and a menu that had to look one up by three columns
 * would be a menu the markup could get wrong.
 */
final readonly class SheetCell
{
    public function __construct(
        public \DateTimeImmutable $day,
        public SheetCellKind $kind,
        public ?string $shiftKey = null,
        public ?string $shiftLabel = null,
        /** The shift's stored palette slot — what `[data-cat]` resolves. */
        public ?int $colour = null,
        public ?string $dutyUuid = null,
        public bool $editedByHand = false,
        public bool $isToday = false,
    ) {
    }
}
