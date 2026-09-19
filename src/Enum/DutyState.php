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
 * HOW FAR A DUTY HAS GOT, and the whole of what a duty can say about itself.
 *
 * There is no fourth case, and in particular there is no "attended": a duty is
 * A PLAN AND NEVER AN OBSERVATION. It says a person is due at a post on a
 * watch; whether they were there is derived from their own check-in and the
 * pings that followed it, and an attended flag here is the front half of the
 * attendance sheet this module's design rejected outright.
 */
enum DutyState: string
{
    /** Generated or drafted, not yet shown to the people on it. */
    case Planned = 'planned';

    /** Standing: the people on it have been told, and the handset serves it. */
    case Published = 'published';

    /**
     * Called off. KEPT, not deleted: a watch that was cancelled is a fact
     * somebody may have to read back, and a deleted row makes a later hole
     * look like one nobody ever planned for.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether this duty asks anybody to be anywhere. */
    public function isStanding(): bool
    {
        return self::Cancelled !== $this;
    }
}
