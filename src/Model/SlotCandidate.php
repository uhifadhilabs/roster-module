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
 * SOMEBODY A SLOT COULD BE FILLED WITH — and, where they could not, the
 * rule that says so in the words the rules card uses.
 *
 * BLOCKED IS SHOWN, NEVER HIDDEN. A picker that omitted the people it will
 * not accept answers "who can I put here" and leaves "why not her" to
 * somebody's memory; the roster is full of rules a duty officer is expected
 * to know, and a screen that enforces them silently teaches nobody.
 *
 * A WARNING IS NOT A BLOCK. Three nights in a row is a thing to notice and
 * then do anyway on a bad week; rest between watches is not. The two are
 * different fields because collapsing them would make every warning feel
 * like a refusal, and then every refusal feel negotiable.
 */
final readonly class SlotCandidate
{
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** Whether the sheet currently has them on this watch. */
        public bool $chosen,
        /** The rule that refuses this pick, in the rules card's own words, or null. */
        public ?string $blockedBy = null,
        /** Something to notice about this pick, which does not refuse it. */
        public ?string $warning = null,
    ) {
    }

    public function isBlocked(): bool
    {
        return null !== $this->blockedBy;
    }

    /** The initials a pill wears. */
    public function initials(): string
    {
        $initials = '';
        foreach (preg_split('/\s+/', trim($this->personName)) ?: [] as $word) {
            if ('' !== $word) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        return mb_substr($initials, 0, 2);
    }
}
