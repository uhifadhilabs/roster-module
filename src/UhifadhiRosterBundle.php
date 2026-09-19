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
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\Module\RosterModuleProvider;

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
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return \is_int($value) ? $value : $fallback;
    }
}
