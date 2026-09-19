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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Service\CyclePlanner;
use Uhifadhi\Roster\Service\RotationGenerator;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml
 * onto installations, and FQCN references stay refactor-safe and
 * phpstan-checked. Imported by UhifadhiRosterBundle::loadExtension(), which
 * keeps only the config-DRIVEN definitions.
 *
 * Everything defined here is defined EXPLICITLY — no autowire(), no
 * autoconfigure(), and ids prefixed with the bundle alias — because this
 * bundle is installed by other projects via Composer, which is what Symfony
 * calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle
 *    alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * REPOSITORIES ARE THE ONE EXCEPTION TO THE PREFIX, and it is not a style
 * choice: ServiceRepositoryCompilerPass keys its locator by SERVICE ID while
 * ContainerRepositoryFactory looks a repository up by CLASS NAME, so a
 * prefixed repository id is a repository Doctrine cannot find.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    foreach ([
        ShiftRepository::class,
        RotationRepository::class,
        RotationPoolMemberRepository::class,
        DutyRepository::class,
        EditedDayRepository::class,
        AbsenceRepository::class,
    ] as $repository) {
        $services->set($repository)
            ->args([service('doctrine')])
            ->tag('doctrine.repository_service');
    }

    // THE ROTATION ALGEBRA. No collaborators at all, which is the point: what
    // a ring says is a calculation, and a calculation that needed a database
    // could not be unit tested against the fourteen shapes a park actually
    // runs.
    $services->set('roster.cycle_planner', CyclePlanner::class);

    // THE HALF THAT WRITES ROWS. Everything it needs is stated; nothing is
    // discovered.
    $services->set('roster.rotation_generator', RotationGenerator::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('roster.cycle_planner'),
            service(ShiftRepository::class),
            service(RotationPoolMemberRepository::class),
            service(DutyRepository::class),
            service(EditedDayRepository::class),
            service(AbsenceRepository::class),
        ]);
};
