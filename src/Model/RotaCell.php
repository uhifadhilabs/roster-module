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

use Uhifadhi\Contracts\Area\DayState;

/**
 * ONE PERSON ON ONE DAY.
 *
 * ONLY TODAY'S CELL CARRIES A STATE. Every other cell is what the rotation
 * SAYS, not what happened — a grid that painted "verified" on a future day
 * would be stating a plan as a fact, which is the one thing this module is
 * built not to do. {@see $state} is null on every column but one, and that
 * is the design's own rule for this tab.
 */
final readonly class RotaCell
{
    public function __construct(
        public RotaCellKind $kind,
        /** "day", "night", "off" — the area's own word, lowercased. */
        public string $label,
        /** "06:00–18:00", or an em dash for a stood-down day. */
        public string $window,
        public bool $isToday = false,
        /** How the day reads — TODAY ONLY, and null on every other column. */
        public ?DayState $state = null,
        /** What a swap mark says under the label: "stood down", "ST-01 · taking it". */
        public ?string $note = null,
    ) {
    }

    public function isSwap(): bool
    {
        return RotaCellKind::SwapGiven === $this->kind || RotaCellKind::SwapTaken === $this->kind;
    }
}
