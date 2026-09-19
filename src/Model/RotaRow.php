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
 * ONE PERSON'S FORTNIGHT.
 *
 * THE ROW IS THE POINT OF THE REBUILD. A grid of POSTS cannot answer "who is
 * working too many nights" — the question a planner opens this tab for — and
 * a grid of people can, because the answer is one row long.
 */
final readonly class RotaRow
{
    /**
     * @param array<string, RotaCell> $cells keyed `2026-09-19`, one per day of the window
     */
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** What the org chart calls them, where it says. */
        public ?string $position,
        public array $cells,
    ) {
    }

    /** How many watches this person stands over the window. */
    public function watches(): int
    {
        return \count(array_filter($this->cells, static fn (RotaCell $cell): bool => RotaCellKind::Off !== $cell->kind));
    }

    /** How many of them are nights — the figure the strip's heaviest reads. */
    public function nights(): int
    {
        return \count(array_filter($this->cells, static fn (RotaCell $cell): bool => RotaCellKind::Night === $cell->kind));
    }
}
