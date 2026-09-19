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

namespace Uhifadhi\Roster\Enum;

/**
 * THE REST A RING MAY NOT BREAK.
 *
 * The consequence is ruled and it is the same for every rule here: A RING THAT
 * BREAKS THE RULE GENERATES A HOLE, NEVER AN ILLEGAL WATCH. The generator
 * drops the duty and the watch comes up short, which is visible on every
 * surface in this module — where silently rostering somebody onto a day after
 * their night is visible nowhere until somebody falls asleep at a gate.
 */
enum RestRule: string
{
    /** Anything the ring produces stands. */
    case None = 'none';

    /** A person who stood a night watch is not put on the next day watch. */
    case NoNightThenDay = 'no_night_then_day';

    /** At least eleven hours between the end of one watch and the start of the next. */
    case ElevenHoursBetween = 'eleven_hours_between';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No rule',
            self::NoNightThenDay => 'No night followed by a day',
            self::ElevenHoursBetween => 'At least 11 h between watches',
        };
    }
}
