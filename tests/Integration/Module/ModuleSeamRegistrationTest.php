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

namespace Uhifadhi\Roster\Tests\Integration\Module;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Tests\Integration\Fixtures\CollectedModules;

/**
 * The installation contract: registering this bundle puts "roster" in the
 * catalogue. A reusable bundle is not autoconfigured, so the
 * "uhifadhi.module" tag is applied by hand in the extension — this test is
 * what proves it stuck.
 */
final class ModuleSeamRegistrationTest extends KernelTestCase
{
    public function testTheRosterModuleReachesTheCatalogueSeam(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);
        $modules = $catalogue->bySlug();

        self::assertArrayHasKey(RosterModuleProvider::SLUG, $modules);
        self::assertInstanceOf(RosterModuleProvider::class, $modules[RosterModuleProvider::SLUG]);
        self::assertSame('Roster', $modules[RosterModuleProvider::SLUG]->name());
        self::assertSame('roster:calendar-clock', $modules[RosterModuleProvider::SLUG]->icon());
    }

    /**
     * The category a deployment configures is the category the catalogue files
     * the tile under — the config value has to reach the provider, not just the
     * container.
     */
    public function testTheConfiguredCategoryReachesTheProvider(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);

        self::assertSame('operations', $catalogue->bySlug()[RosterModuleProvider::SLUG]->category());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
