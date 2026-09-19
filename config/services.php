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
use Uhifadhi\Roster\Repository\AreaRosterSettingsRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Service\CyclePlanner;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Twig\RosterTrailExtension;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml
 * onto installations, and FQCN references stay refactor-safe and
 * phpstan-checked. Imported by UhifadhiRosterBundle::loadExtension(), which
 * keeps only the config-DRIVEN definitions — the parameters below are read
 * there, and the controllers are registered there because they sit behind the
 * SecurityBundle guard.
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
        StationWatchRepository::class,
        AreaRosterSettingsRepository::class,
    ] as $repository) {
        $services->set($repository)
            ->args([service('doctrine')])
            ->tag('doctrine.repository_service');
    }

    // THE ROTATION ALGEBRA. No collaborators at all, which is the point: what
    // a ring says is a calculation, and a calculation that needed a database
    // could not be unit tested against the shapes a park actually runs.
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

    // WHAT THE AREA RUNS ON. Created from the installation's starting values
    // on first ask, and never read from config again once the row exists.
    $services->set('roster.settings', RosterSettingsService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AreaRosterSettingsRepository::class),
            param('roster.default_ping_interval_minutes'),
            param('roster.default_catchment_metres'),
        ]);

    // THE AREA'S ONE LIST OF NAMED SHIFTS, seeded from the configured
    // vocabulary the first time anybody asks for it.
    $services->set('roster.shift_vocabulary', ShiftVocabularyService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ShiftRepository::class),
            service(DutyRepository::class),
            param('roster.shifts'),
        ]);

    // THE POSTS ON THIS MODULE'S BOOKS. No create-on-read, unlike the
    // settings row: a post is given a watch deliberately.
    $services->set('roster.station_watches', StationWatchService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(StationWatchRepository::class),
            service(RotationRepository::class),
            service('roster.settings'),
            param('roster.default_silence_window_minutes'),
            param('roster.default_offline_after_minutes'),
        ]);

    // `roster_url()` — the URL of a screen, or null where the installation did
    // not mount it. Twig's own path() THROWS on an unregistered route, so a
    // breadcrumb naming the area's screens is a breadcrumb that takes the
    // whole page down in an installation that mounted one screen fewer.
    $services->set('roster.twig.trail', RosterTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

    // THE IDENTITY BAND'S FIGURES. It reads the AREA's station and posting
    // repositories by their published classes — a module may type-hint the
    // platform it requires; the platform never names the module.
    $services->set('roster.identity', RosterIdentityService::class)
        ->args([
            service(StationWatchRepository::class),
            service('Uhifadhi\Bundle\AreaBundle\Repository\StationRepository'),
            service('Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository'),
            service(RotationRepository::class),
            service('roster.shift_vocabulary'),
            service('roster.settings'),
        ]);
};
