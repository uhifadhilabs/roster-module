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
 * ONE END OF A TRADE, AS THE GRID DRAWS IT.
 *
 * TWO MARKS PER OPEN OFFER: swapA on the watch being given up, swapB on the
 * person being asked. NEITHER DUTY HAS MOVED — until the handset answers,
 * both stand exactly where the rotation put them, and the marks say a trade
 * is in flight rather than that anybody has been moved.
 */
final readonly class SwapMark
{
    public function __construct(
        public RotaCellKind $kind,
        public string $label,
        public string $note,
    ) {
    }
}
