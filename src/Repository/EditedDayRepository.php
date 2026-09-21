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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\EditedDay;

/**
 * The days a person has touched, and therefore the days the generator leaves
 * alone.
 *
 * @extends ServiceEntityRepository<EditedDay>
 */
final class EditedDayRepository extends ServiceEntityRepository
{
    /** The key {@see markedBetween()} files a station-wide mark under. */
    public const string WHOLE_STATION = '*';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EditedDay::class);
    }

    /**
     * THE STATION-WIDE MARK — the one with no ranger on it, which is the
     * only kind the generator has ever written or read.
     */
    public function findOneFor(Station $station, \DateTimeImmutable $onDay): ?EditedDay
    {
        return $this->findOneBy(['station' => $station, 'onDay' => $onDay->setTime(0, 0), 'person' => null]);
    }

    /** AND ONE RANGER'S DAY — the mark the sheet's cells draw. */
    public function findOneForPerson(Station $station, \DateTimeImmutable $onDay, UserInterface $person): ?EditedDay
    {
        return $this->findOneBy(['station' => $station, 'onDay' => $onDay->setTime(0, 0), 'person' => $person]);
    }

    /**
     * EVERY HAND MARK IN A WINDOW, keyed by ranger and day — one query for
     * a sheet that draws 34 rangers over 28 days, because asking per cell
     * is how a planner's tab ends up slower than the month it plans.
     *
     * The station-wide marks come back under {@see WHOLE_STATION}, since a
     * day nobody may touch is a day every ranger's cell is marked on.
     *
     * @return array<string, list<string>> ranger uuid (or {@see WHOLE_STATION}) => Y-m-d
     */
    public function markedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $marks = [];
        foreach ($this->inWindow($area, $from, $through) as $mark) {
            $person = $mark->getPerson();
            $key = null === $person ? self::WHOLE_STATION : (string) $person->getUuidString();
            $marks[$key][] = $mark->getOnDay()->format('Y-m-d');
        }

        return $marks;
    }

    /**
     * THE MARKS THEMSELVES, over an area's stations in a window.
     *
     * @return list<EditedDay>
     */
    public function inWindow(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<EditedDay> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.station', 's')
            ->andWhere('s.area = :area')
            ->andWhere('e.onDay BETWEEN :from AND :through')
            ->setParameter('area', $area)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->getQuery()
            ->getResult();

        return $rows;
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
            // THE GENERATOR READS THE STATION-WIDE MARK AND ONLY THAT. A
            // ranger's own day is the sheet's to protect; standing a whole
            // station down because one cell was edited would be the
            // opposite of what the mark says.
            ->andWhere('e.person IS NULL')
            ->andWhere('e.onDay BETWEEN :from AND :through')
            ->setParameter('station', $station)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['on_day']->format('Y-m-d'), $rows);
    }
}
