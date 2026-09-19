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
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\ShiftWindow;

/**
 * THE AREA'S SHIFT VOCABULARY. Every read here is confined to one area: the
 * list is that area's own, and a query that forgot the area filter would be
 * the one bug area-scoping exists to rule out.
 *
 * @extends ServiceEntityRepository<Shift>
 */
final class ShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    /**
     * The whole list in its own order, CLOSED ONES INCLUDED — the Settings
     * section dims a closed row, it does not hide it, and a duty that names a
     * closed shift still has to be able to print its label.
     *
     * @return list<Shift>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The shifts a rotation may still be built out of.
     *
     * @return list<Shift>
     */
    public function findOpenByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area, 'closedAt' => null], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByKey(AreaOfInterest $area, string $key): ?Shift
    {
        return $this->findOneBy(['area' => $area, 'key' => $key]);
    }

    /**
     * THE VOCABULARY AS THE PLANNER THINKS IN IT — keyed by shift key, closed
     * shifts included, because a ring written before a shift was closed still
     * has to be measurable against the rest rule.
     *
     * @return array<string, ShiftWindow>
     */
    public function windowsFor(AreaOfInterest $area): array
    {
        $windows = [];
        foreach ($this->findByArea($area) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
        }

        return $windows;
    }
}
