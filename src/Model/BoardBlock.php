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
 * ONE WATCH DRAWN ON THE DAY BOARD — where it sits on the twenty-four
 * hours, and who is on it.
 *
 * THE GEOMETRY IS A PERCENTAGE, not a pixel: the row is a proportion of
 * whatever width the screen gives it, and a block measured in pixels would
 * be wrong on every screen but one.
 *
 * `fromYesterday` IS NOT DECORATION. It is the morning tail of a night
 * watch that began the day before, and a board that did not say so would
 * have the same two people apparently on duty twice.
 */
final readonly class BoardBlock
{
    /**
     * @param list<string> $people the names on this watch, in the order the duties came back
     */
    public function __construct(
        public string $shiftKey,
        public string $label,
        public float $leftPercent,
        public float $widthPercent,
        public array $people,
        public bool $fromYesterday,
        public bool $crossesMidnight,
    ) {
    }

    public function count(): int
    {
        return \count($this->people);
    }
}
