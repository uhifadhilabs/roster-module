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

namespace Uhifadhi\Roster\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures the module in
 * config/packages/roster.yaml:
 *
 *   roster:
 *     module_category: operations    # catalogue category for the tile
 *     dev_tools: false         # dev-only commands (when@dev / when@test)
 *
 * Deliberately small. A deployment's shift vocabulary (duty types, shift
 * patterns, station kinds) is DEPLOYMENT vocabulary and will be configured
 * here — but only once the roster design has ruled on the domain model.
 * Nothing is guessed ahead of that ruling, and the tree is closed, so an
 * invented key fails loudly.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class RosterConfiguration
{
    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The roster root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('module_category')
                    ->info('Catalogue category the Rosters module is filed under in each area.')
                    ->defaultValue('operations')->cannotBeEmpty()
                ->end()
                ->booleanNode('dev_tools')
                    ->info('Register dev-only tooling (seeders, fixtures). The recipe enables this via when@dev/when@test.')
                    ->defaultFalse()
                ->end()
            ->end()
        ;
    }
}
