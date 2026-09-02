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

namespace UhifadhiLabs\Roster\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as DoctrineBundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UhifadhiLabs\Roster\UhifadhiLabsRosterBundle;

/**
 * The smoke test: registering the bundle in a real kernel compiles a real
 * container. Everything else in this repo rides on that.
 */
final class BundleBootTest extends KernelTestCase
{
    public function testTheBundleBootsInAHostKernel(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('UhifadhiLabsRosterBundle', $kernel->getBundles());
        self::assertInstanceOf(
            UhifadhiLabsRosterBundle::class,
            $kernel->getBundle('UhifadhiLabsRosterBundle'),
        );
    }

    /**
     * Config lives under "roster:", not the class-derived
     * "uhifadhi_labs_roster:" — the alias is part of the host contract.
     */
    public function testItsConfigurationIsKeyedByTheRosterAlias(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('roster', $kernel->getBundle('UhifadhiLabsRosterBundle')
            ->getContainerExtension()?->getAlias());
    }

    /**
     * Zero-config persistence: the bundle maps its own entity directory, so a
     * host never writes a doctrine mappings block for roster tables. The
     * mapping is registered now and stays empty until the domain lands.
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
        self::assertArrayHasKey('UhifadhiLabs\Roster\Entity', $driver->getDrivers());
        // Nothing mapped yet, and that is the point: the seam is wired, the
        // domain arrives with the design ruling.
        self::assertSame([], $em->getMetadataFactory()->getAllMetadata());
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
