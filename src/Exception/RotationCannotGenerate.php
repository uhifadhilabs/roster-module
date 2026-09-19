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
     * A PER-TEAM ROTATION HAS NO POST, and a duty is "one watch, one station,
     * one day" by ruling — so there is no honest station to file a squad's
     * tour against.
     *
     * OPEN, AND NAMED HERE RATHER THAN GUESSED AT. The design draws a
     * per-team rotation ("a squad's cycle travels with them") and shows it
     * generating, but never says where a team's watch stands. The two answers
     * are a base post chosen when the rotation is created, or a duty whose
     * station is genuinely empty — and that is a domain verdict, not a
     * modelling preference.
     */
    public static function becauseItStandsAtNoPost(Rotation $rotation): self
    {
        return new self(\sprintf(
            'The "%s" rotation carries a cycle for a team rather than a post, and a duty is one watch at one station on one day — so there is nowhere to write its watches. Give the rotation a post, or wait on the verdict for where a team\'s watch stands.',
            $rotation->getTeamName() ?? $rotation->getUuid()->toRfc4122(),
        ));
    }
}
