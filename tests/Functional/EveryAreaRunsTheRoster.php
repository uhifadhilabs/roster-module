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

namespace Uhifadhi\Roster\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Roster\Module\RosterModuleProvider;

/**
 * THE AREAS IN THIS SUITE ARE RUNNING THE ROSTER — said once, out loud,
 * because it is not true by default.
 *
 * The registry closes a module's routes in an area that has parked it or
 * never took it, and answers 404 for both. A fixture area written straight
 * into the database has no row in the per-area ledger at all, so every page
 * in this bundle would answer 404 there — correctly, and uselessly.
 *
 * So the fixture does what an installation does: reconcile the catalogue,
 * then switch the module on for the area. Quietly exempting the suite from
 * the gate instead would test a product nobody runs.
 */
trait EveryAreaRunsTheRoster
{
    protected function everyAreaRunsTheRoster(EntityManagerInterface $em): void
    {
        $em->flush();

        $sync = static::getContainer()->get('test_public.'.RegistrySyncService::class);
        \assert($sync instanceof RegistrySyncService);
        $sync->sync();

        $areaModules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($areaModules instanceof AreaModuleService);

        foreach ($em->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $areaModules->install($area, RosterModuleProvider::SLUG);
        }
    }
}
