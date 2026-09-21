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
use Uhifadhi\Roster\Entity\StationRuleException;
use Uhifadhi\Roster\Enum\RuleKind;

/**
 * WHAT EACH STATION DOES DIFFERENTLY — and, by its silence, which stations
 * do not.
 *
 * @extends ServiceEntityRepository<StationRuleException>
 */
final class StationRuleExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StationRuleException::class);
    }

    /**
     * @return list<StationRuleException>
     */
    public function findByStation(Station $station): array
    {
        /** @var list<StationRuleException> $rows */
        $rows = $this->findBy(['station' => $station], ['kind' => 'ASC']);

        return $rows;
    }

    /**
     * EVERY EXCEPTION IN ONE AREA, KEYED BY STATION — asked once for a page
     * that draws a row per station, because asking per row is twelve
     * queries for one answer.
     *
     * @return array<string, list<StationRuleException>> keyed by station uuid
     */
    public function findByArea(AreaOfInterest $area): array
    {
        /** @var list<StationRuleException> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.station', 's')
            ->andWhere('s.area = :area')
            ->setParameter('area', $area)
            ->orderBy('e.kind', 'ASC')
            ->getQuery()
            ->getResult();

        $byStation = [];
        foreach ($rows as $row) {
            $byStation[(string) $row->getStation()->getUuidString()][] = $row;
        }

        return $byStation;
    }

    public function findOneByStationAndKind(Station $station, RuleKind $kind): ?StationRuleException
    {
        return $this->findOneBy(['station' => $station, 'kind' => $kind]);
    }
}
