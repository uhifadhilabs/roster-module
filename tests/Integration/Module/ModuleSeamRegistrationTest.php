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

namespace UhifadhiLabs\Roster\Tests\Integration\Module;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UhifadhiLabs\Roster\Module\RosterModuleProvider;
use UhifadhiLabs\Roster\Tests\Integration\Fixtures\CollectedModules;

/**
 * The host contract: installing this bundle puts "rosters" in the catalogue.
 * A reusable bundle is not autoconfigured, so the "uhifadhi.module" tag is
 * applied by hand in the extension — this test is what proves it stuck.
 */
final class ModuleSeamRegistrationTest extends KernelTestCase
{
    public function testTheRostersModuleReachesTheHostsCatalogueSeam(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);
        $modules = $catalogue->bySlug();

        self::assertArrayHasKey('rosters', $modules);
        self::assertInstanceOf(RosterModuleProvider::class, $modules['rosters']);
        self::assertSame('Rosters', $modules['rosters']->name());
        self::assertSame('calendar-clock', $modules['rosters']->icon());
    }

    /**
     * The category a deployment configures is the category the host files the
     * tile under — the config value has to reach the provider, not just the
     * container.
     */
    public function testTheConfiguredCategoryReachesTheProvider(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);

        self::assertSame('flux', $catalogue->bySlug()['rosters']->category());
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
