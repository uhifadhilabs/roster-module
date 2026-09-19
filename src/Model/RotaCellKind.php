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
 * WHAT ONE CELL OF THE ROTA SAYS.
 *
 * FIVE KINDS AND NOT FOUR: the two swap marks are kinds of their own because
 * a trade in flight is neither the watch somebody has nor the watch they do
 * not have — it is a watch being handed over, and until the handset answers
 * BOTH cells still stand where the rotation put them. Collapsing them into
 * "day"/"off" would make an offer invisible on the one surface a planner
 * arranges it from.
 */
enum RotaCellKind: string
{
    /** A watch inside the day. */
    case Day = 'd';

    /** A watch that crosses midnight. */
    case Night = 'n';

    /** The ring stood them down. Not a hole: nobody was asked for. */
    case Off = 'o';

    /** This watch is being given up — an offer is out on it. */
    case SwapGiven = 'swapA';

    /** This person is being asked to take a watch. */
    case SwapTaken = 'swapB';
}
