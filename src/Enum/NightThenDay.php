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
 * A DAY WATCH THE MORNING AFTER A NIGHT WATCH — what the area does about it.
 *
 * SEPARATE FROM THE REST RULE, and deliberately. Eleven hours of rest is
 * satisfied by a night ending at 06:00 and a day beginning at 18:00; it is
 * NOT what stops somebody who came off at 06:00 being put back on at 07:30,
 * because that is a shape of day rather than a gap in hours. An area that
 * runs a two-shift station may genuinely want it and say so.
 */
enum NightThenDay: string implements RuleChoiceInterface
{
    case Never = 'never';
    case WarnMe = 'warn';
    case Allow = 'allow';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never',
            self::WarnMe => 'Allow it, and warn me',
            self::Allow => 'Allow it',
        };
    }

    /** Whether a fill may write the day at all. */
    public function permits(): bool
    {
        return self::Never !== $this;
    }
}
