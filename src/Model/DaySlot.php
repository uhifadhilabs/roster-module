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
 * ONE WATCH ON ONE DAY, AS SOMETHING TO FILL — a post, a shift, how many
 * the pattern asks for, and who the sheet has on it.
 *
 * A SLOT IS NOT A DUTY. The duty is what gets written when the sheet is
 * published; until then a slot is a question — "the gate asks two tomorrow
 * night, who?" — and the whole point of the screen is that the question is
 * visible before anybody has answered it. A screen built on duties alone
 * can only show what somebody already decided.
 *
 * IT CARRIES ITS CANDIDATES AND WHY EACH IS OR IS NOT ONE. A picker that
 * simply omitted the blocked people would answer "who can I put here" and
 * leave "why not her" to somebody's memory of the rules. The rules card on
 * the same page lists them; each blocked pill says which one it hit.
 */
final readonly class DaySlot
{
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public string $shiftKey,
        public string $shiftLabel,
        /** How many the pattern asks for here. */
        public int $asks,
        /** @var list<SlotCandidate> everybody the post's ring may draw on, chosen or not */
        public array $candidates,
    ) {
    }

    /** How many of the candidates are on it. */
    public function filled(): int
    {
        return \count(array_filter($this->candidates, static fn (SlotCandidate $c): bool => $c->chosen));
    }

    /** The watches the pattern asked for that nobody is on. */
    public function shortfall(): int
    {
        return max(0, $this->asks - $this->filled());
    }

    public function isShort(): bool
    {
        return $this->shortfall() > 0;
    }

    /** @return list<SlotCandidate> */
    public function chosen(): array
    {
        return array_values(array_filter($this->candidates, static fn (SlotCandidate $c): bool => $c->chosen));
    }
}
