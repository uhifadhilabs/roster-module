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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RuleValue;

/**
 * A RULE IS A NUMBER THE USER TYPES AND A UNIT THEY PICK.
 *
 * RULED 20 sep. Owner: "forcing predefined options is stupid." There is no
 * menu of blessed values anywhere — late after 2 hours, offline after 1
 * day, a ping every 30 minutes, a check-in within 1.5 km. This is the
 * object that carries one, and the arithmetic that turns it into the
 * minutes and metres every reading in the module is already written
 * against.
 *
 * THE STORED PAIR IS WHAT THE USER TYPED, never the canonical number. "1
 * day" and "1440 minutes" are the same threshold and not the same answer:
 * the field has to come back saying what somebody put in it, or the first
 * person to open the page after saving finds their day turned into
 * minutes.
 */
final class RuleValueTest extends TestCase
{
    /**
     * @return iterable<string, array{float, RuleUnit, int}>
     */
    public static function durations(): iterable
    {
        yield '30 minutes' => [30.0, RuleUnit::Minutes, 30];
        yield '2 hours' => [2.0, RuleUnit::Hours, 120];
        yield '1 day' => [1.0, RuleUnit::Days, 1440];
        yield '1.5 hours rounds to the minute' => [1.5, RuleUnit::Hours, 90];
        yield '14 days' => [14.0, RuleUnit::Days, 20160];
    }

    #[DataProvider('durations')]
    public function testADurationReadsAsMinutes(float $value, RuleUnit $unit, int $minutes): void
    {
        self::assertSame($minutes, new RuleValue($value, $unit)->toMinutes());
    }

    /**
     * @return iterable<string, array{float, RuleUnit, int}>
     */
    public static function distances(): iterable
    {
        yield '1.5 km' => [1.5, RuleUnit::Kilometres, 1500];
        yield '800 m' => [800.0, RuleUnit::Metres, 800];
        yield '2 km' => [2.0, RuleUnit::Kilometres, 2000];
    }

    #[DataProvider('distances')]
    public function testADistanceReadsAsMetres(float $value, RuleUnit $unit, int $metres): void
    {
        self::assertSame($metres, new RuleValue($value, $unit)->toMetres());
    }

    /**
     * WHAT WAS TYPED COMES BACK. A value stored canonically and rendered in
     * whatever unit looked tidiest would turn somebody's "1 day" into "1440
     * minutes" the moment they saved it.
     */
    public function testThePairComesBackAsItWasTyped(): void
    {
        $rule = new RuleValue(1.0, RuleUnit::Days);

        self::assertSame(1.0, $rule->value);
        self::assertSame(RuleUnit::Days, $rule->unit);
        self::assertSame('1 day', $rule->label());
    }

    /** A number is printed the way somebody wrote it, with no trailing nothing. */
    public function testAWholeNumberIsPrintedWhole(): void
    {
        self::assertSame('30 minutes', new RuleValue(30.0, RuleUnit::Minutes)->label());
        self::assertSame('1.5 km', new RuleValue(1.5, RuleUnit::Kilometres)->label());
    }

    /**
     * A UNIT BELONGS TO A KIND. Asking for a distance in hours is not a
     * value the form can produce and not one this object will hold: the
     * five rules are three durations and one distance, and a mismatch is a
     * bug rather than a preference.
     */
    public function testAUnitThatDoesNotMeasureTheKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RuleKind::CheckInWithin->valueOf(2.0, RuleUnit::Hours);
    }

    public function testEveryKindOffersOnlyTheUnitsThatMeasureIt(): void
    {
        self::assertSame([RuleUnit::Minutes, RuleUnit::Hours, RuleUnit::Days], RuleKind::LateAfter->units());
        self::assertSame([RuleUnit::Kilometres, RuleUnit::Metres], RuleKind::CheckInWithin->units());
    }

    /** Zero and below are not a threshold, whatever the unit. */
    public function testAThresholdIsAPositiveNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RuleValue(0.0, RuleUnit::Hours);
    }
}
