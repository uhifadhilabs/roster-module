<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Rosters Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\Roster\Module\RosterModuleProvider;

final class RosterModuleProviderTest extends TestCase
{
    public function testDeclaresTheRostersModule(): void
    {
        $provider = new RosterModuleProvider('flux');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('rosters', $provider->slug());
        self::assertSame('Rosters', $provider->name());
        self::assertSame('flux', $provider->category());
        self::assertSame('Duty rosters and shift check-ins', $provider->dataSource());
        self::assertSame('calendar-clock', $provider->icon());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('pressure', new RosterModuleProvider('pressure')->category());
    }

    /**
     * The module has no screens yet — the roster design has not been ruled on,
     * so there is nothing to link to and the host renders the module through
     * its generic module page. This assertion is the reminder: it changes the
     * day the first roster route lands.
     */
    public function testRendersThroughTheHostsGenericModulePageUntilItsScreensLand(): void
    {
        self::assertNull(new RosterModuleProvider('flux')->entryRoute());
    }

    /**
     * Permissions are declared alongside the routes that check them. There are
     * no routes yet, so declaring a permission here would hand admins something
     * that guards nothing.
     */
    public function testDeclaresNoPermissionsYet(): void
    {
        self::assertSame([], new RosterModuleProvider('flux')->permissions());
    }
}
