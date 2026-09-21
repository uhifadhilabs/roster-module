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
 * WHAT A FILL DOES WITH A DAY ITS OWN RULES FORBID.
 *
 * NEVER FILLED WRONGLY is the whole of it: a fill that quietly stood
 * somebody on a watch their rest rule forbids would make the rules
 * decoration. The area chooses which of the two honest answers it wants —
 * the day left as a gap anybody can see, or the day filled and raised as
 * needing a decision — and there is no third answer where the rule is
 * simply not applied.
 */
enum ForbiddenDay: string implements RuleChoiceInterface
{
    case LeftUnfilled = 'unfilled';
    case FilledAndFlagged = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::LeftUnfilled => 'Left unfilled',
            self::FilledAndFlagged => 'Filled, and flagged',
        };
    }
}
