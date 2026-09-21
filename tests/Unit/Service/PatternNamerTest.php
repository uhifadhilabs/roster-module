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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternNamer;

/**
 * A PATTERN'S NAME IS DERIVED FROM ITS CYCLE, and nobody types it.
 *
 * RULED 21 sep. Owner: "the name should not be written by user but rather
 * generated from the config the user chooses." There is no name field
 * anywhere; the editor shows the derived name live and the server derives
 * it again on save, so the register, the sheet's fill row and the sheet
 * cannot print three different names.
 *
 * THE LONG FORM, FOR EVERY PART — `<n> days of <shift>`, singular `1 day of
 * night`, and an off part `<n> off`. The short form was rejected for one
 * reason: it needed to pluralise a shift name, and THE PRODUCT NEVER
 * PLURALISES A SHIFT NAME. It does not know one shift name from another —
 * they are words this area typed — and it will not be taught English.
 * Longer, and never wrong.
 */
final class PatternNamerTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, array<string, string>, string}>
     */
    public static function cycles(): iterable
    {
        yield 'the canonical one' => [
            ['day', 'day', 'night', 'night', Cycle::OFF],
            ['day' => 'day', 'night' => 'night'],
            '2 days of day, 2 days of night, 1 off',
        ];

        yield 'an office week' => [
            ['office', 'office', 'office', 'office', 'office', Cycle::OFF, Cycle::OFF],
            ['office' => 'office'],
            '5 days of office, 2 off',
        ];

        yield 'one day is singular' => [
            ['night', Cycle::OFF],
            ['night' => 'night'],
            '1 day of night, 1 off',
        ];

        yield 'a shift whose name is two words is never bent' => [
            ['radio night', 'radio night', 'radio night', Cycle::OFF, Cycle::OFF],
            ['radio night' => 'radio night'],
            '3 days of radio night, 2 off',
        ];

        yield 'four on four off' => [
            ['day', 'day', 'day', 'day', Cycle::OFF, Cycle::OFF, Cycle::OFF, Cycle::OFF],
            ['day' => 'day'],
            '4 days of day, 4 off',
        ];

        yield 'a cycle of nothing but work' => [
            ['day', 'day'],
            ['day' => 'day'],
            '2 days of day',
        ];

        /*
         * THE SAME SHIFT TWICE, WITH A BREAK BETWEEN, IS TWO PARTS. The name
         * is a reading of the cycle in order, not a tally of it: a ring that
         * works, rests and works again says so, and collapsing the two runs
         * would name two different cycles the same thing.
         */
        yield 'a run that comes back is its own part' => [
            ['day', Cycle::OFF, 'day', 'day'],
            ['day' => 'day'],
            '1 day of day, 1 off, 2 days of day',
        ];
    }

    /**
     * @param list<string>          $positions
     * @param array<string, string> $labels
     */
    #[DataProvider('cycles')]
    public function testTheNameIsTheCycleReadInOrder(array $positions, array $labels, string $expected): void
    {
        self::assertSame($expected, PatternNamer::derive(Cycle::of($positions), $labels));
    }

    /**
     * A SHIFT IS NAMED IN THIS AREA'S OWN WORDS, and renaming it renames
     * every pattern built from it — which is exactly why the name is
     * derived on every read rather than stored beside the cycle.
     */
    public function testRenamingTheShiftRenamesThePattern(): void
    {
        $cycle = Cycle::of(['day', 'day', Cycle::OFF]);

        self::assertSame('2 days of day, 1 off', PatternNamer::derive($cycle, ['day' => 'day']));
        self::assertSame('2 days of early, 1 off', PatternNamer::derive($cycle, ['day' => 'early']));
    }

    /**
     * A KEY THE AREA NO LONGER NAMES STILL READS. It can only happen to a
     * cycle whose shift row was deleted out from under it — which the
     * product does not do, because a shift is closed and never deleted —
     * and a pattern that printed nothing at all would be a row nobody could
     * identify to fix.
     */
    public function testAShiftTheAreaCannotDescribeIsPrintedByItsKey(): void
    {
        self::assertSame('2 days of dawn, 1 off', PatternNamer::derive(Cycle::of(['dawn', 'dawn', Cycle::OFF]), []));
    }
}
