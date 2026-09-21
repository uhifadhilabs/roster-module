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

namespace Uhifadhi\Roster\Service;

use Uhifadhi\Roster\Model\Cycle;

/**
 * A PATTERN'S NAME, DERIVED FROM ITS CYCLE — and nobody ever types it.
 *
 * RULED 21 sep. Owner: "the name should not be written by user but rather
 * generated from the config the user chooses." So there is no name field:
 * the editor shows this live as the sentence changes, and the server runs
 * the same function again on save. The register, the sheet's fill row and
 * the sheet read one derivation, so they cannot print three different
 * names for one object.
 *
 * IT IS DERIVED ON EVERY READ AND STORED NOWHERE, which is what makes
 * renaming a shift rename every pattern built from it. A name kept beside
 * the cycle would be a second copy going quietly stale the first time
 * somebody corrected a spelling.
 *
 * THE LONG FORM, FOR EVERY PART. `<n> days of <shift>`, singular `1 day of
 * night`; an off part is `<n> off`. The short form was rejected for one
 * reason and it is worth keeping written down: it needed to pluralise a
 * shift name. THE PRODUCT NEVER PLURALISES A SHIFT NAME — the words are
 * this area's own, it does not know one from another, and it will not be
 * taught English. Longer, and never wrong.
 *
 * A STATIC FUNCTION, because it is one: a cycle and this area's words in,
 * a sentence out, no state and nothing to inject. It is not an `XxxService`
 * for the same reason {@see \Uhifadhi\Roster\Model\WatchDeclaration} is not.
 */
final readonly class PatternNamer
{
    /**
     * @param array<string, string> $labels this area's shift names, keyed by the key a cycle stores
     */
    public static function derive(Cycle $cycle, array $labels): string
    {
        $parts = [];

        foreach (self::runs($cycle) as [$entry, $days]) {
            $parts[] = Cycle::OFF === $entry
                ? $days.' off'
                : \sprintf('%d %s of %s', $days, 1 === $days ? 'day' : 'days', $labels[$entry] ?? $entry);
        }

        return implode(', ', $parts);
    }

    /**
     * THE CYCLE AS RUNS OF THE SAME DAY, IN ORDER — the one thing a name
     * and an editor's sentence both need.
     *
     * IN ORDER, AND NEVER TALLIED. A ring that works, rests and works again
     * is three parts; folding the two runs of work together would give two
     * different cycles the same name.
     *
     * @return list<array{string, int}> the entry, and how many days of it in a row
     */
    public static function runs(Cycle $cycle): array
    {
        $runs = [];

        foreach ($cycle->positions as $entry) {
            $last = \count($runs) - 1;

            if ($last >= 0 && $runs[$last][0] === $entry) {
                ++$runs[$last][1];

                continue;
            }

            $runs[] = [$entry, 1];
        }

        return $runs;
    }
}
