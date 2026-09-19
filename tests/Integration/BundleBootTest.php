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

namespace Uhifadhi\Roster\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as DoctrineBundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * The smoke test: registering the bundle beside the real core compiles a real
 * container. Everything else in this repo rides on that.
 */
final class BundleBootTest extends KernelTestCase
{
    public function testTheBundleBootsInARealInstallation(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('UhifadhiRosterBundle', $kernel->getBundles());
        self::assertInstanceOf(
            UhifadhiRosterBundle::class,
            $kernel->getBundle('UhifadhiRosterBundle'),
        );
    }

    /**
     * Config lives under "roster:", not the class-derived "uhifadhi_roster:"
     * — the alias is part of the installation contract.
     */
    public function testItsConfigurationIsKeyedByTheRosterAlias(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('roster', $kernel->getBundle('UhifadhiRosterBundle')
            ->getContainerExtension()?->getAlias());
    }

    /**
     * Zero-config persistence: the bundle maps its own entity directory, so an
     * installation never writes a doctrine mappings block for roster tables.
     */
    public function testItMapsItsOwnEntityDirectory(): void
    {
        self::bootKernel();

        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $doctrine->getManager();
        $driver = $em->getConfiguration()->getMetadataDriverImpl();
        // DoctrineBundle decorates the chain (custom id-generator support);
        // the namespace registry lives on the chain underneath.
        if ($driver instanceof DoctrineBundleMappingDriver) {
            $driver = $driver->getDriver();
        }

        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertArrayHasKey('Uhifadhi\Roster\Entity', $driver->getDrivers());
    }

    /**
     * THE SHIFT VOCABULARY REACHES THE CONTAINER, not just the config tree. A
     * parameter nobody sets is the failure mode a tree test cannot see: the
     * defaults pass their own unit test and the services that read them get
     * nothing.
     */
    public function testTheShiftVocabularyAndStartingValuesBecomeParameters(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame(RosterConfiguration::DEFAULT_SHIFTS, $container->getParameter('roster.shifts'));
        self::assertSame(30, $container->getParameter('roster.default_ping_interval_minutes'));
        self::assertSame(120, $container->getParameter('roster.default_silence_window_minutes'));
        self::assertSame(1440, $container->getParameter('roster.default_offline_after_minutes'));
        self::assertSame(1500, $container->getParameter('roster.default_catchment_metres'));
        self::assertSame(42, $container->getParameter('roster.default_horizon_days'));
        self::assertFalse($container->getParameter('roster.dev_tools'));
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
