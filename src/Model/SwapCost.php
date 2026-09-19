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
 * WHAT A TRADE COSTS — the checks a duty officer needs before sending an
 * offer, each stated as a fact rather than folded into a yes or a no.
 *
 * A SWAP IS NEVER APPLIED BY PICKING TWO CELLS ALONE. The design's own line,
 * and this is the object that makes it true: the two cells are the trade,
 * and these are the things that make it a good or a bad one. Somebody still
 * has to read them and somebody still has to accept.
 *
 * A FAILING CHECK IS NOT A BLOCK. The rest rule is the area's own setting
 * and a duty officer may knowingly send an offer that breaks it — what the
 * card must never do is send one QUIETLY. So each check carries whether it
 * holds and the sentence that says why.
 */
final readonly class SwapCost
{
    /**
     * @param list<array{label: string, holds: bool, detail: string}> $checks in the order the card reads them
     */
    public function __construct(
        public array $checks,
    ) {
    }

    /** Whether every check holds. A card shows this; it does not act on it. */
    public function allHold(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check['holds']) {
                return false;
            }
        }

        return true;
    }
}
