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
 * ONE WATCH THE RING PRODUCES — before anybody has checked whether the person
 * at that place in the pool is available, and before it has become a row.
 *
 * It names a POSITION IN THE POOL and not a person, because the ring knows
 * nothing about people: it knows that whoever stands fourth enters four days
 * later than whoever stands first. Turning a position into a person is the
 * generator's job and needs the database; working out what the ring says does
 * not, which is why this half is a pure calculation with its own unit tests.
 */
final readonly class PlannedDuty
{
    public function __construct(
        public int $poolPosition,
        public string $shiftKey,
        public \DateTimeImmutable $onDay,
    ) {
    }
}
