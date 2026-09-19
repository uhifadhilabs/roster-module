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
 * WHY SOMEBODY IS NOT AVAILABLE FOR A WATCH.
 *
 * A REST DAY IS NOT ON THIS LIST, deliberately. A ring that stands somebody
 * down produces no state at all — they are not expected, they have failed at
 * nothing, and recording a rest day as an absence would colour every one of
 * them on every surface. An absence is a departure from what the ring already
 * says.
 *
 * NEITHER IS "outside the park". That is a CHECK-IN STATUS a ranger declares
 * on the handset on the day, and it belongs to the area. An absence is
 * recorded ahead of the day, by somebody else, so the hole it makes can be
 * filled before it opens.
 */
enum AbsenceKind: string
{
    case Leave = 'leave';
    case Sick = 'sick';
    case Course = 'course';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Leave',
            self::Sick => 'Sick',
            self::Course => 'Course',
            self::Other => 'Other',
        };
    }
}
