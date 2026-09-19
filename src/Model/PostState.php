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
 * HOW A POST READS — three states and one modifier, derived on every read
 * and stored never.
 *
 * NOTHING IS STORED, so nothing can drift: there is no offline flag to be
 * left set after a handset comes back, and no nightly job whose failure
 * would quietly freeze a whole area in yesterday's truth.
 *
 * UNSTAFFED IS NOT A FOURTH STATE. A post can be reporting AND unstaffed at
 * once — somebody opened it and the plan puts nobody on it today — and
 * collapsing the two would send a person out to fix a radio that works.
 * It is asked separately, {@see PostPresence::shortfall()}.
 *
 * OFFLINE DESCRIBES THE POST, NEVER THE PEOPLE. It is computed from the
 * check-ins and pings of whoever is posted there, and a post with nobody
 * posted at all is exactly why eighteen days of silence reads as an offline
 * post and never as an absent ranger.
 */
enum PostState: string
{
    /** Evidence newer than this post's own silence window. */
    case Reporting = 'reporting';

    /**
     * Nothing inside the window. A WARNING AND NOT A FAULT, and it clears
     * itself the moment something lands — nothing is dismissed by hand.
     */
    case Late = 'late';

    /** Nothing at all past the long threshold. A fact about the post. */
    case Offline = 'offline';

    /**
     * The post declares no watch, so it is not expected to be manned and
     * cannot be late. Only offline, once the long threshold passes.
     */
    case NoWatch = 'no_watch';

    public function label(): string
    {
        return match ($this) {
            self::Reporting => 'reporting',
            self::Late => 'late',
            self::Offline => 'offline',
            self::NoWatch => 'no watch',
        };
    }
}
