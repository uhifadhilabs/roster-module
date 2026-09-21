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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\SheetPreference;

/**
 * How each person likes the sheet.
 *
 * @extends ServiceEntityRepository<SheetPreference>
 */
final class SheetPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SheetPreference::class);
    }

    public function findOneByPersonAndArea(UserInterface $person, AreaOfInterest $area): ?SheetPreference
    {
        return $this->findOneBy(['person' => $person, 'area' => $area]);
    }
}
