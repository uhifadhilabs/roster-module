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

namespace Uhifadhi\Roster\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Model\Cycle;

/**
 * THE RINGS A NEW ROTATION MAY START FROM.
 *
 * THE POINT OF EVERY CASE HERE is that a preset is a SHAPE and the post's
 * own watches fill it. The design draws its presets in one park's words —
 * "2 days, 2 nights, 1 off" — and a module that stored those words would
 * offer five rings to a park that stands an office watch and a radio night
 * and can use none of them.
 */
final class RotationPresetTest extends TestCase
{
    public function testAPresetIsFilledWithThePostsOwnWatches(): void
    {
        $ring = RotationPreset::TwoOfEachThenOff->ringFor(['office', 'radio']);

        self::assertSame(['office', 'office', 'radio', 'radio', Cycle::OFF], $ring->positions);
    }

    public function testTheCountsStartAtOnePerWatchTheRingStands(): void
    {
        // ONE, AND NOT WHAT THE RING PRODUCES. The declaration is what a
        // shortfall is measured against, so a rotation that asked for
        // whatever it could staff could never be short.
        self::assertSame(['day' => 1, 'night' => 1], RotationPreset::TwoOfEachThenOff->slotsFor(['day', 'night']));
    }

    /**
     * @return iterable<string, array{RotationPreset, list<string>, int}>
     */
    public static function shapes(): iterable
    {
        yield 'one of each' => [RotationPreset::OneOfEachThenOff, ['day', 'night'], 3];
        yield 'two of each' => [RotationPreset::TwoOfEachThenOff, ['day', 'night'], 5];
        yield 'four on four off' => [RotationPreset::FourOnFourOff, ['day'], 8];
        yield 'a tour' => [RotationPreset::TenOnFourOff, ['day'], 14];
        yield 'a weekly set' => [RotationPreset::AWeeklySet, ['day'], 7];
    }

    /**
     * @param list<string> $expects
     */
    #[DataProvider('shapes')]
    public function testEveryShapeMakesARingOfTheLengthItsNameClaims(RotationPreset $preset, array $expects, int $length): void
    {
        self::assertSame($length, $preset->ringFor($expects)->length());
    }

    /**
     * A POST THAT DECLARES NO WATCH GETS NO RING, and it is refused with a
     * sentence rather than given a ring of nothing but off days — which
     * would generate nothing for ever and look broken instead of empty.
     */
    public function testAPostThatStandsNoWatchIsRefusedWithASentence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares no watch/');

        RotationPreset::OneOfEachThenOff->ringFor([]);
    }

    /** A watch named twice is one watch — a ring must not double it by accident. */
    public function testARepeatedWatchIsOneWatch(): void
    {
        self::assertSame(['day', Cycle::OFF], RotationPreset::OneOfEachThenOff->ringFor(['day', 'day'])->positions);
    }

    public function testEveryPresetIsNamedInWordsAParkCanRead(): void
    {
        foreach (RotationPreset::cases() as $preset) {
            self::assertNotSame('', $preset->label());
        }
    }
}
