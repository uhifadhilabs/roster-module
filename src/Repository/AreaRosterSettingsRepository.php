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

namespace Uhifadhi\Roster\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\AreaRosterSettings;

/**
 * One row per area, or none until the area has been looked at.
 *
 * @extends ServiceEntityRepository<AreaRosterSettings>
 */
final class AreaRosterSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaRosterSettings::class);
    }

    public function findOneForArea(AreaOfInterest $area): ?AreaRosterSettings
    {
        return $this->findOneBy(['area' => $area]);
    }
}
