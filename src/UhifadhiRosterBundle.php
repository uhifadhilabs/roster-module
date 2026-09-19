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

namespace Uhifadhi\Roster;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Shell\RosterConfigurationSections;
use Uhifadhi\Roster\Shell\RosterModuleTabs;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * ROSTER — who is due on watch, where and when.
 *
 * The module owns the ROTATION a post or a team runs, the DUTIES it generates,
 * the SWAP between two of them, the ABSENCE that makes a hole, and the WATCH a
 * station expects. It owns no station, no posting, no check-in and no
 * position: those are the area's, and the presence this module draws is READ
 * from the area rather than computed here.
 *
 * Zero-config: registering the bundle maps its own entities, registers its own
 * migrations and serves its own assets, so an installation writes no doctrine
 * block, no migrations path and no asset path for it.
 */
final class UhifadhiRosterBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S OWN VOCABULARY IS SERVED FROM — what AssetMapper
     * serves public/roster.css under. Stated once because it has two readers
     * that must never disagree: this module's own base template, which links
     * it on every roster page, and the contribution that hands it to an AREA
     * rendering this module's presence card on its overview.
     */
    public const string STYLESHEET = 'bundles/uhifadhiroster/roster.css';

    /**
     * The AssetMapper namespace for the bundle's Stimulus controllers. It MUST
     * be the npm-style form of the composer package name: Flex keys
     * assets/controllers.json by '@'.<package name> and StimulusBundle
     * resolves that key back to this directory.
     */
    public const string ASSET_NAMESPACE = '@uhifadhi/roster-module';

    /**
     * StimulusBundle's own normalisation of {@see ASSET_NAMESPACE} — '@'
     * dropped, '/' and '_' to '-'. What a template's data-controller starts
     * with.
     */
    public const string CONTROLLER_PREFIX = 'uhifadhi--roster-module--';

    /** Config lives under "roster:", not the class-derived "uhifadhi_roster:". */
    protected string $extensionAlias = 'roster';

    public function configure(DefinitionConfigurator $definition): void
    {
        RosterConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under
        // `bundles/uhifadhiroster` and content-versioned — no config here, no
        // assets:install.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // Guarded on BOTH conditions: a kernel may have no framework extension,
        // and AssetMapper is optional.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => self::ASSET_NAMESPACE,
                    ],
                ],
            ]);
        }

        /*
         * THE SQL THAT CREATES THIS MODULE'S TABLES, SHIPPED WITH IT.
         *
         * An installation runs `doctrine:migrations:migrate` and writes no
         * version for roster_* — the same way it writes none for the core.
         * `doctrine:migrations:diff` stays what it runs for the entities IT
         * writes, and after an update of this package it must report no
         * changes.
         *
         * The guard is not decoration: an application may have this bundle
         * and not the migrations bundle, and there this module simply has no
         * history to run.
         *
         * The namespace is mapped by this package's composer.json with an
         * EXPLICIT psr-4 prefix. `Uhifadhi\Roster\` is `src/`, so a lowercase
         * `migrations/` directory would resolve under nothing — a failure
         * that shows up only on a case-sensitive filesystem, which is to say
         * in an installation and not here.
         *
         * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
         */
        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Roster\\Migrations' => __DIR__.'/../migrations',
                ],
            ], prepend: true);
        }

        // Zero-config persistence: the bundle maps its own entities, so
        // installations never write a doctrine mappings block for roster_*.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiRoster' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Roster\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php
        // for the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the registry's
        // catalogue seed and module grid. The tag is applied BY HAND: the core
        // registers ModuleProviderInterface for autoconfiguration, but that
        // only fires for autoconfigured services and a reusable bundle does
        // not autoconfigure.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'operations';
        $services->set('roster.module_provider', RosterModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        // THE DEPLOYMENT'S SHIFT VOCABULARY, as a parameter for the services
        // that read it. Shape-checked on the way in: phpstan max will demand
        // it, and a malformed tree is better caught at compile time than by a
        // day board that draws a watch with no window.
        $shifts = $config['shifts'] ?? RosterConfiguration::DEFAULT_SHIFTS;
        $builder->setParameter('roster.shifts', \is_array($shifts) ? array_values($shifts) : RosterConfiguration::DEFAULT_SHIFTS);

        // THE STARTING VALUES a new area setting, station watch or rotation is
        // created with. Read when something is CREATED, never at display time —
        // what a station or an area actually runs at is its own stored value.
        $defaults = $config['defaults'] ?? [];
        $defaults = \is_array($defaults) ? $defaults : [];
        $builder->setParameter('roster.default_ping_interval_minutes', self::intOr($defaults['ping_interval_minutes'] ?? null, RosterConfiguration::DEFAULT_PING_INTERVAL_MINUTES));
        $builder->setParameter('roster.default_silence_window_minutes', self::intOr($defaults['silence_window_minutes'] ?? null, RosterConfiguration::DEFAULT_SILENCE_WINDOW_MINUTES));
        $builder->setParameter('roster.default_offline_after_minutes', self::intOr($defaults['offline_after_minutes'] ?? null, RosterConfiguration::DEFAULT_OFFLINE_AFTER_MINUTES));
        $builder->setParameter('roster.default_catchment_metres', self::intOr($defaults['catchment_metres'] ?? null, RosterConfiguration::DEFAULT_CATCHMENT_METRES));
        $builder->setParameter('roster.default_horizon_days', self::intOr($defaults['horizon_days'] ?? null, RosterConfiguration::DEFAULT_HORIZON_DAYS));

        // Dev-only tooling hangs off a visible flag in installation config, the
        // way framework.test does, rather than an env() check buried in bundle
        // code. The recipe enables it via when@dev / when@test.
        $builder->setParameter('roster.dev_tools', true === ($config['dev_tools'] ?? false));

        /*
         * THE MODULE'S TWO DECLARATIONS TO THE FRAME — where its data lives
         * and what is on its configure page. Tagged BY HAND: a reusable
         * bundle is not autoconfigured, and a declaration that forgot its tag
         * gets a module with no tab strip, no children in the sidebar tree
         * and no Configure page, with nothing anywhere saying why.
         */
        $services->set('roster.module_tabs', RosterModuleTabs::class)
            ->tag(ModuleTabsInterface::TAG);

        $services->set('roster.configuration_sections', RosterConfigurationSections::class)
            ->args([service('request_stack'), service(AreaOfInterestRepository::class)])
            ->tag(ConfigurationSectionsInterface::TAG);

        // THE OVERVIEW TAB. A read, so it is registered unconditionally: an
        // installation with no firewall still has a roster to look at.
        $services->set('roster.controller.overview', RosterController::class)
            ->args([service('twig'), service('roster.identity')])
            ->public();
        $services->alias(RosterController::class, 'roster.controller.overview')->public();

        /*
         * THE CONFIGURE SECTIONS ARE REGISTERED ONLY WHERE SECURITYBUNDLE IS
         * ACTUALLY IN THE KERNEL. Every write on that page changes how an area
         * runs its roster and rides on "roster.manage"; without an
         * authorization checker there is nothing to enforce it, so an
         * installation in that state gets NO configure routes (they fail
         * loudly) rather than three open write endpoints.
         *
         * The guard reads kernel.bundles, as FrameworkExtension does. Two
         * other checks look right and are not: hasExtension('security')
         * cannot be used while an extension is loading, because the builder is
         * then a restricted MergeExtensionConfigurationContainerBuilder that
         * does not expose other extensions; and interface_exists() only proves
         * a class is autoloadable — security-core is one of this bundle's DEV
         * dependencies, so it autoloads in our own test runs even when
         * SecurityBundle is absent, and the services would then reference
         * security.* ids that do not exist.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);

        // The templates hide the Configure action where the page cannot exist.
        $builder->setParameter('roster.configure_screens', $hasSecurity);

        if ($hasSecurity) {
            $services->set('roster.controller.configure', RosterConfigureController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('roster.identity'),
                    service('roster.settings'),
                    service('roster.shift_vocabulary'),
                    service('roster.station_watches'),
                    service(StationRepository::class),
                    service(RotationRepository::class),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(RosterConfigureController::class, 'roster.controller.configure')->public();
        }
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return \is_int($value) ? $value : $fallback;
    }
}
