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
 * ONE THING WAITING ON SOMEBODY.
 *
 * THREE KINDS, AND THE CARD SAYS WHICH. A missing check-in, a claim the
 * device disagrees with, and a watch nobody is on are acted on by different
 * people in different ways: one is a radio call, one is a conversation, one
 * is a change to the plan. A single red list would hide which of the three
 * anybody looking can actually do something about — so the kind is carried,
 * never folded away.
 *
 * THE TONE IS THE HOUSE'S, NOT THIS MODULE'S. A module has no hue; a flag is
 * the installation's fail colour wherever it appears.
 */
final readonly class Decision
{
    public const string MISSING = 'missing';
    public const string FLAGGED = 'flagged';
    public const string HOLE = 'hole';

    public function __construct(
        /** One of MISSING, FLAGGED, HOLE. */
        public string $kind,
        /** What the chip says. */
        public string $kindLabel,
        /** The house state class the chip wears. */
        public string $tone,
        /** Whose problem it is — a person, or the watch itself. */
        public string $who,
        /** Where it is: the post, and the shift. */
        public string $where,
        /** The one fact that makes it answerable. */
        public string $fact,
    ) {
    }

    public static function missing(string $person, string $where, string $fact): self
    {
        return new self(self::MISSING, 'no check-in', 'st-warn', $person, $where, $fact);
    }

    public static function flagged(string $person, string $where, string $fact): self
    {
        return new self(self::FLAGGED, 'flagged', 'st-fail', $person, $where, $fact);
    }

    public static function hole(string $watch, string $where, string $fact): self
    {
        return new self(self::HOLE, 'hole', 'st-fail', $watch, $where, $fact);
    }
}
