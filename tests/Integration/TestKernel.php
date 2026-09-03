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

namespace Uhifadhi\Roster\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Uhifadhi\Roster\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Roster\UhifadhiRosterBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Smallest possible host app for integration tests: framework + doctrine + the
 * rosters bundle. No database connection is opened — the module has no entities
 * until the design rules on the domain model, so these tests prove only that
 * the bundle compiles into a real container and plugs into the host's module
 * seam.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new UhifadhiRosterBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(ROSTER_TEST_DATABASE_URL)%'],
        ]);

        // Stands in for the HOST's module catalogue: the host collects every
        // service tagged "uhifadhi.module" and seeds its catalogue from them.
        // Tagged services are private, so this collector is what makes the
        // bundle's contribution observable from a test.
        $container->services()
            ->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])
            ->public();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/roster-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/roster-module-tests/log';
    }
}
