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

namespace Uhifadhi\Roster\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Model\Cycle;

final class CycleTest extends TestCase
{
    public function testTheRingReadsForwardAndRepeats(): void
    {
        $cycle = Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]);

        self::assertSame(5, $cycle->length());
        self::assertSame('day', $cycle->at(0));
        self::assertSame('night', $cycle->at(2));
        self::assertSame(Cycle::OFF, $cycle->at(4));
        // And round again, for ever.
        self::assertSame('day', $cycle->at(5));
        self::assertSame('night', $cycle->at(12));
    }

    /**
     * A DATE BEFORE THE ANCHOR IS AN ORDINARY QUESTION for a ring that has
     * always repeated, and PHP's % keeps the sign of the dividend — so -1 % 5
     * is -1 and the naive version reads off the end of the list. This is the
     * test that fails if the wrap is ever simplified back.
     */
    public function testTheRingReadsBackwardsPastTheAnchor(): void
    {
        $cycle = Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]);

        self::assertSame(Cycle::OFF, $cycle->at(-1));
        self::assertSame('night', $cycle->at(-2));
        self::assertSame('day', $cycle->at(-5));
        self::assertSame(Cycle::OFF, $cycle->at(-6));
    }

    public function testARingOfOneDayIsLegalAndAlwaysSaysTheSameThing(): void
    {
        $cycle = Cycle::of(['day']);

        self::assertSame('day', $cycle->at(0));
        self::assertSame('day', $cycle->at(97));
        self::assertSame('day', $cycle->at(-97));
    }

    public function testARingOfNoDaysIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cycle::of([]);
    }

    public function testABlankDayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cycle::of(['day', '  ']);
    }

    public function testItNamesTheShiftsItStandsWithoutRepeats(): void
    {
        $cycle = Cycle::of(['day', 'day', 'night', Cycle::OFF, 'night']);

        self::assertSame(['day', 'night'], $cycle->shiftKeys());
    }

    public function testARingOfNothingButOffStandsNoShifts(): void
    {
        self::assertSame([], Cycle::of([Cycle::OFF, Cycle::OFF])->shiftKeys());
    }

    public function testItRoundTripsThroughItsStoredShape(): void
    {
        $positions = ['office', 'office', 'office', 'radio', Cycle::OFF];

        self::assertSame($positions, Cycle::fromStored(Cycle::of($positions)->toStored())->toStored());
    }

    public function testAStoredValueThatIsNotAListOfStringsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cycle::fromStored(['day', 3]);
    }

    public function testAStoredValueThatIsNotAListAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cycle::fromStored('day,night');
    }
}
