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
 * A RULE WHOSE ANSWER IS A CHOICE AND NOT A MEASUREMENT.
 *
 * RULED 21 sep. Two of the four filling rules are not thresholds: "a day
 * watch the morning after a night watch" and "a day the rules forbid" have
 * no number in them at all, and asking somebody to express either as a
 * quantity would be asking them to encode an answer rather than give it.
 *
 * SO THE SHAPE OF A RULE IS TWO SHAPES, not one stretched over both. A
 * measured rule stores a number and a unit ({@see \Uhifadhi\Roster\Model\RuleValue});
 * a chosen rule stores one case of an enum that implements this. The row on
 * the card draws whichever of the two its kind is, which is why
 * {@see RuleKind::isChoice()} exists and why nothing downstream has to guess.
 */
interface RuleChoiceInterface extends \BackedEnum
{
    /** What the option reads as in the select — the design's own words. */
    public function label(): string;
}
