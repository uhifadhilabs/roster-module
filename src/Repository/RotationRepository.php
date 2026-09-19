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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Rotation;

/**
 * The standing rotations of one area.
 *
 * @extends ServiceEntityRepository<Rotation>
 */
final class RotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rotation::class);
    }

    /**
     * Every rotation declared in this area, stood-down ones included — the
     * Configure page lists them and says which are standing.
     *
     * @return list<Rotation>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area], ['id' => 'ASC']);
    }

    /**
     * The rotations the generator runs.
     *
     * @return list<Rotation>
     */
    public function findActiveByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area, 'active' => true], ['id' => 'ASC']);
    }

    /**
     * Whatever ring a post runs, or null. A post with no rotation is never
     * counted, reported on or called a hole.
     */
    public function findOneForStation(Station $station): ?Rotation
    {
        return $this->findOneBy(['station' => $station, 'active' => true]);
    }

    public function findOneByUuid(AreaOfInterest $area, Uuid $uuid): ?Rotation
    {
        return $this->findOneBy(['area' => $area, 'uuid' => $uuid]);
    }
}
