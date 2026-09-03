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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiRosterBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (the module category).
 *
 * Everything defined here is defined EXPLICITLY — no autowire(), no autoconfigure(),
 * and ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * Empty for now, and on purpose: the roster design has not been ruled on, so
 * there are no repositories, services or controllers to wire. The file exists so
 * the first one lands in the right place, in the right style.
 */
return static function (ContainerConfigurator $container): void {
    $container->services();
};
