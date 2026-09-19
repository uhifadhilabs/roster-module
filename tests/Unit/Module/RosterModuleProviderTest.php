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

namespace Uhifadhi\Roster\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Roster\Module\RosterModuleProvider;

final class RosterModuleProviderTest extends TestCase
{
    public function testDeclaresTheRosterModule(): void
    {
        $provider = new RosterModuleProvider('operations');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('Roster', $provider->name());
        self::assertSame('operations', $provider->category());
        self::assertSame('Rotations, duties and the area\'s check-ins', $provider->dataSource());
        self::assertSame('calendar-clock', $provider->icon());
    }

    /**
     * THE SLUG IS SINGULAR. It is the word in every url this module serves,
     * the key the per-area ledger switches it on by, and the string every
     * contribution to the area overview has to repeat. The pre-ruling scaffold
     * said "rosters"; changing it back would silently orphan every one of
     * those, so it is asserted against the constant AND against the literal.
     */
    public function testTheSlugIsRoster(): void
    {
        self::assertSame('roster', RosterModuleProvider::SLUG);
        self::assertSame('roster', new RosterModuleProvider('operations')->slug());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('pressure', new RosterModuleProvider('pressure')->category());
    }

    /**
     * Until this module owns a route, an area's tile renders through the
     * platform's generic module page. This assertion is the reminder, and it
     * changes in the commit that ships the Overview route — never before, or
     * every tile links at a 404.
     */
    public function testRendersThroughTheGenericModulePageUntilItsScreensLand(): void
    {
        self::assertNull(new RosterModuleProvider('operations')->entryRoute());
    }

    /**
     * Permissions are declared alongside the routes that check them. There are
     * no routes yet, so declaring one here would hand admins a permission that
     * guards nothing.
     */
    public function testDeclaresNoPermissionsYet(): void
    {
        self::assertSame([], new RosterModuleProvider('operations')->permissions());
    }
}
