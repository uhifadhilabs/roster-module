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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\EditedDay;

/**
 * The days a person has touched, and therefore the days the generator leaves
 * alone.
 *
 * @extends ServiceEntityRepository<EditedDay>
 */
final class EditedDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EditedDay::class);
    }

    public function findOneFor(Station $station, \DateTimeImmutable $onDay): ?EditedDay
    {
        return $this->findOneBy(['station' => $station, 'onDay' => $onDay->setTime(0, 0)]);
    }

    /**
     * THE PROTECTED DAYS IN A WINDOW, as Y-m-d strings, because that is the
     * shape the generator compares against and turning each row back into a
     * date object per planned duty is work nobody reads.
     *
     * @return list<string>
     */
    public function protectedDaysBetween(Station $station, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<array{on_day: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.onDay AS on_day')
            ->andWhere('e.station = :station')
            ->andWhere('e.onDay BETWEEN :from AND :through')
            ->setParameter('station', $station)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['on_day']->format('Y-m-d'), $rows);
    }
}
