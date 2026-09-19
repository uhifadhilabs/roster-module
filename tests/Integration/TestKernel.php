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

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use FundiStadi\PostGISBundle\FundiStadiPostGISBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Roster\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Roster\UhifadhiRosterBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * THE SMALLEST INSTALLATION THIS BUNDLE CAN LIVE IN, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security, the five core
 * bundles, and this module — against a REAL PostGIS database
 * (ROSTER_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * NOTHING HERE IS A COPY. Every bundle below is the published one, because a
 * copy cannot hold a contract — it pins whatever the copyist believed.
 *
 * THE AREA BUNDLE IS NOT OPTIONAL SCENERY HERE. It owns the station a watch is
 * stood at, the posting that puts a person there, and the check-ins presence
 * is derived from. A duty carries a foreign key to a station, so a suite
 * without AreaBundle would be asserting against a schema no installation has.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        // The atlas's plates are built on UX Map and its Leaflet bridge, and
        // the Live tab draws through them.
        yield new UXMapBundle();
        yield new DoctrineBundle();
        // An installation has this, and this module ships a history for it to
        // run. Without it the bundle's migrations_paths block is guarded out.
        yield new DoctrineMigrationsBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
        // The per-area catalogue this module registers itself in.
        yield new RegistryBundle();
        // The frame every roster screen renders in, and the widget framework
        // the Overview tab IS.
        yield new ShellBundle();
        // The map chrome and the plate the Live tab draws on.
        yield new AtlasBundle();
        // The account class every duty, swap and absence is keyed by.
        yield new TeamBundle();
        // The station a watch is stood at, the posting that puts a person
        // there, and the check-ins presence is read from.
        yield new AreaBundle();
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
            // loginUser() needs a stateful firewall and flashes need a session;
            // the mock file storage is the documented test-env choice.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Every write this module ships carries a CSRF token, so the token
            // manager has to exist here as it does in a real installation
            // (FrameworkBundle only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // The security block a skeleton installation writes, minus the screens
        // this kernel does not mount: the hashers, the entity provider over the
        // account TeamBundle owns, and the checker that refuses a deactivated
        // one. Permission checks go through the real AuthorizationChecker
        // rather than a stub that always says yes.
        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                ],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(ROSTER_TEST_DATABASE_URL)%',
            ],
            'orm' => [
                // The skeleton's own choice (config/packages/doctrine.yaml),
                // mirrored here so this module's metadata-driven SQL is
                // exercised against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO 'mappings' AND NO 'resolve_target_entities', both
                // deliberately: every entity this module points at arrives with
                // the bundle that owns it and maps itself.
            ],
        ]);

        // With on-demand fetching on, a name no file answers to is fetched from
        // a remote API and cached, so a missing glyph stays invisible until the
        // deployment with no outbound network draws a blank square. An
        // installation turns it off, and this kernel is an installation.
        $container->extension('ux_icons', [
            'iconify' => ['on_demand' => false],
        ]);

        // UX Map draws nothing at all until a renderer is named.
        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        // Public aliases so tests can hold the bundle's private services,
        // keyed by class name for readability (see IntegrationTestCase). They
        // exist only because a bundle test kernel has no controllers yet:
        // unreferenced private services are removed at compile time. Delete an
        // alias the day a real reference exists.
        foreach ([
            \Uhifadhi\Roster\Service\CyclePlanner::class => 'roster.cycle_planner',
            \Uhifadhi\Roster\Service\RotationGenerator::class => 'roster.rotation_generator',
            \Uhifadhi\Roster\Repository\ShiftRepository::class => \Uhifadhi\Roster\Repository\ShiftRepository::class,
            \Uhifadhi\Roster\Repository\RotationRepository::class => \Uhifadhi\Roster\Repository\RotationRepository::class,
            \Uhifadhi\Roster\Repository\DutyRepository::class => \Uhifadhi\Roster\Repository\DutyRepository::class,
            \Uhifadhi\Roster\Repository\EditedDayRepository::class => \Uhifadhi\Roster\Repository\EditedDayRepository::class,
            \Uhifadhi\Roster\Repository\AbsenceRepository::class => \Uhifadhi\Roster\Repository\AbsenceRepository::class,
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        // Stands in for the catalogue seed's collector: the registry collects
        // every service tagged "uhifadhi.module", and tagged services are
        // private, so this is what makes the bundle's contribution observable
        // from a test.
        $container->services()
            ->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }
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
