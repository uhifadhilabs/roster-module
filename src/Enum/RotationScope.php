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
 * WHAT A ROTATION IS DECLARED FOR, and the difference is not filing — it is
 * what happens when the people change.
 *
 * A POST'S CYCLE STANDS WHEN ITS PEOPLE CHANGE: the gate runs two twelves
 * whoever is posted to it, so the ring belongs to the place and a new posting
 * simply joins the pool. A TEAM'S CYCLE TRAVELS WITH THEM: a response team on
 * a ten-on-four-off tour carries its ring wherever it is sent, and the ring
 * would mean nothing pinned to a station it may not see this month.
 */
enum RotationScope: string
{
    /** One cycle a station runs. The rotation names a station. */
    case Post = 'post';

    /** One cycle a squad carries. The rotation names the squad instead. */
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Per post',
            self::Team => 'Per team',
        };
    }
}
