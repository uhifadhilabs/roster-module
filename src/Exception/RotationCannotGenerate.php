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

namespace Uhifadhi\Roster\Exception;

use Uhifadhi\Roster\Entity\Rotation;

/**
 * THE GENERATOR WAS ASKED FOR SOMETHING IT CANNOT HONESTLY PRODUCE.
 *
 * Loud rather than empty, deliberately. A generator that returned "nothing
 * written" for a rotation it structurally cannot run looks exactly like a
 * rotation whose pool is away, and the difference matters: one is a park
 * running short this week and the other is a rotation that will never
 * produce anything at all.
 */
final class RotationCannotGenerate extends \RuntimeException
{
    /**
     * THE ROTATION NAMES NO POST AT ALL — neither a station of its own nor,
     * for a squad, a base post to file its tour against.
     *
     * UNREACHABLE FROM THE PRODUCT, and kept anyway. Both ways of setting a
     * rotation's scope take a station, so a rotation saved through this
     * module always has one; what this catches is a row written around them —
     * a hand-built fixture, a direct SQL insert, a half-finished import. A
     * silent "nothing written" there would look exactly like a rotation whose
     * pool is all away, which is a very different problem.
     */
    public static function becauseItStandsAtNoPost(Rotation $rotation): self
    {
        return new self(\sprintf(
            'The "%s" rotation names no post, so there is nowhere to write its watches: a duty is one watch at one station on one day. A per-post rotation stands at its station; a per-team one is filed at its base post.',
            $rotation->getTeamName() ?? $rotation->getUuid()->toRfc4122(),
        ));
    }
}
