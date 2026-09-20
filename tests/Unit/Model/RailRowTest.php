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
use Uhifadhi\Roster\Model\RailRow;

/**
 * A RAIL ROW EITHER CENTRES THE PLATE OR IS INERT, ruled.
 *
 * A ROW WITHOUT SOMEWHERE TO GO IS NOT A LINK. The alternative is a link
 * that answers a click by doing nothing, which teaches a reader that the
 * rail's rows are not clickable — and the ones that are then look broken
 * too. The station table requires a point, but a zone with no geometry and
 * an installation whose imports are half-done both produce this row, so
 * the surface asks the row rather than assuming.
 */
final class RailRowTest extends TestCase
{
    public function testARowWithSomewhereToGoCentresThePlate(): void
    {
        self::assertTrue(new RailRow('01a0-uuid', 'north gate post', 'ST-01', 2)->centres());
    }

    public function testARowWithNowhereToGoIsInert(): void
    {
        self::assertFalse(new RailRow(null, 'radio room', 'ST-99', 0)->centres());
    }
}
