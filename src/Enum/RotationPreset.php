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

use Uhifadhi\Roster\Model\Cycle;

/**
 * THE RINGS A NEW ROTATION MAY START FROM — the design's "Preset" row.
 *
 * A PRESET WRITES THE RING; THE RING IS WHAT IS SAVED. Nothing here is
 * stored: a preset is a way of choosing a ring, and keeping the choice would
 * make an old rotation change shape the day somebody edited a preset.
 *
 * THE SHIFT KEYS ARE THE POST'S, NEVER THIS ENUM'S. The design draws its
 * presets in one park's vocabulary — "2 days, 2 nights, 1 off" — and a
 * module that hardcoded `day` and `night` would offer an installation with
 * an `office` and a `radio night` watch five rings it cannot use. So each
 * preset is a SHAPE, and it is filled with the watches the post itself
 * declares.
 *
 * A POST THAT DECLARES NO WATCH GETS NO RING. Every shape here needs at
 * least one shift to repeat, and a ring of nothing but off days generates
 * nothing for ever — which is the one rotation that looks broken rather
 * than empty.
 */
enum RotationPreset: string
{
    /** One of each watch the post stands, then a day off. */
    case OneOfEachThenOff = 'one_of_each_then_off';

    /** Two of each watch the post stands, then a day off. */
    case TwoOfEachThenOff = 'two_of_each_then_off';

    /** Four days on the post's first watch, then four off. */
    case FourOnFourOff = 'four_on_four_off';

    /** Ten days on the post's first watch, then four off — a tour. */
    case TenOnFourOff = 'ten_on_four_off';

    /** A seven-day set of the post's first watch. */
    case AWeeklySet = 'a_weekly_set';

    public function label(): string
    {
        return match ($this) {
            self::OneOfEachThenOff => 'One of each watch, then a day off',
            self::TwoOfEachThenOff => 'Two of each watch, then a day off',
            self::FourOnFourOff => 'Four on, four off — an 8-day ring',
            self::TenOnFourOff => 'Ten on, four off — a tour',
            self::AWeeklySet => 'A weekly set — 7 days on',
        };
    }

    /**
     * THE RING THIS SHAPE MAKES OF THE WATCHES A POST STANDS.
     *
     * @param list<string> $expects the post's own shift keys, in the order it declares them
     *
     * @throws \InvalidArgumentException when the post declares no watch to repeat
     */
    public function ringFor(array $expects): Cycle
    {
        $expects = array_values(array_unique(array_filter($expects, static fn (string $key): bool => '' !== $key)));

        if ([] === $expects) {
            throw new \InvalidArgumentException('This post declares no watch, so there is nothing for a ring to repeat. Give it one on the Watches section first.');
        }

        $first = $expects[0];

        $positions = match ($this) {
            self::OneOfEachThenOff => [...$expects, Cycle::OFF],
            self::TwoOfEachThenOff => [...self::twice($expects), Cycle::OFF],
            self::FourOnFourOff => [...array_fill(0, 4, $first), ...array_fill(0, 4, Cycle::OFF)],
            self::TenOnFourOff => [...array_fill(0, 10, $first), ...array_fill(0, 4, Cycle::OFF)],
            self::AWeeklySet => array_fill(0, 7, $first),
        };

        return Cycle::of($positions);
    }

    /**
     * HOW MANY THE POST ASKS FOR PER WATCH, to start from — one each.
     *
     * ONE, AND NOT WHAT THE RING HAPPENS TO PRODUCE. The declaration is what
     * a shortfall is measured against, so a new rotation that asked for
     * whatever its pool could staff could never be short, and a hole would
     * be undetectable on the day it first mattered.
     *
     * @param list<string> $expects
     *
     * @return array<string, int>
     */
    public function slotsFor(array $expects): array
    {
        $slots = [];
        foreach ($this->ringFor($expects)->shiftKeys() as $key) {
            $slots[$key] = 1;
        }

        return $slots;
    }

    /**
     * @param list<string> $expects
     *
     * @return list<string>
     */
    private static function twice(array $expects): array
    {
        $doubled = [];
        foreach ($expects as $key) {
            $doubled[] = $key;
            $doubled[] = $key;
        }

        return $doubled;
    }
}
