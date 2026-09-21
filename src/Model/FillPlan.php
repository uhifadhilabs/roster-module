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
 * WHAT A FILL WOULD DO, OR DID — the line under the fill row, as facts
 * rather than as a sentence the template assembles.
 *
 * PREVIEW AND FILL ANSWER THE SAME OBJECT, deliberately: the number
 * somebody reads before pressing the button and the number they get are
 * produced by one walk over one set of rules, so they cannot disagree.
 */
final readonly class FillPlan
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $through,
        /** Days this fill writes a watch on. */
        public int $days,
        /** Days somebody edited by hand, which a fill never touches. */
        public int $leftAlone,
        /** Days the area's own rules forbid — left unfilled, or filled and flagged. */
        public int $forbidden,
    ) {
    }

    public function isNothing(): bool
    {
        return 0 === $this->days;
    }
}
