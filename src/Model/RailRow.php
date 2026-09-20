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
 * ONE ROW IN A LIST BESIDE THE PLATE — a post or a zone.
 *
 * EVERY ROW CENTRES THE PLATE ON WHAT IT NAMES, and a row that cannot be
 * centred is NOT A LINK. That is the rule the `centre` field exists for: a
 * post with no point and a zone with no geometry are inert and unmarked,
 * rather than links that do nothing when clicked. An affordance that does
 * not work is worse than none, because it teaches the reader to distrust
 * the ones that do.
 *
 * THE ROW STATES THE ONE THING WRONG WITH ITS ANSWER. "3 live" is the
 * count; "1 stale" beside it is why the count cannot be taken at face
 * value. Folding the two would leave a reader with a number they have no
 * reason to doubt and every reason to.
 */
final readonly class RailRow
{
    public function __construct(
        /** What the plate centres on: the subject's own uuid, or null where it cannot be found. */
        public ?string $centre,
        public string $name,
        /** The line under the name — a code and a count, or an area and a count. */
        public string $detail,
        /** How many of its people have a live position this minute. */
        public int $live,
        /** The one thing wrong with that count, or null. */
        public ?string $wrong = null,
        /**
         * A category POSITION, 1-based, for the swatch — never a colour.
         * Null where the row carries no swatch at all.
         */
        public ?int $category = null,
        /** Whether this module keeps a watch here at all. */
        public bool $quiet = false,
    ) {
    }

    /** Whether clicking it does anything. */
    public function centres(): bool
    {
        return null !== $this->centre;
    }
}
