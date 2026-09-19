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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\StationWatch;

/**
 * The roster's four columns on the area's stations.
 *
 * @extends ServiceEntityRepository<StationWatch>
 */
final class StationWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StationWatch::class);
    }

    public function findOneForStation(Station $station): ?StationWatch
    {
        return $this->findOneBy(['station' => $station]);
    }

    /**
     * THE POSTS ON THIS MODULE'S BOOKS — the area's stations that have been
     * given a watch, and no others. The join is to the STATION's area, because
     * a watch has no area of its own: it is a row hanging off somebody else's
     * record and inherits where that record lives.
     *
     * @return list<StationWatch>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        /** @var list<StationWatch> $watches */
        $watches = $this->createQueryBuilder('w')
            ->join('w.station', 's')
            ->andWhere('s.area = :area')
            ->setParameter('area', $area)
            ->orderBy('s.code', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $watches;
    }

    /**
     * How many posts in this area run a watch — the identity band's own
     * figure, counted rather than listed because the band prints a number.
     */
    public function countByArea(AreaOfInterest $area): int
    {
        $count = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->join('w.station', 's')
            ->andWhere('s.area = :area')
            ->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
