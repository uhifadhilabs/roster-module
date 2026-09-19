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
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;

/**
 * A rotation's pool, in the order its people enter the ring.
 *
 * @extends ServiceEntityRepository<RotationPoolMember>
 */
final class RotationPoolMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RotationPoolMember::class);
    }

    /**
     * THE ORDER IS THE PLAN, so it is stated in the query and never left to
     * the database: position 0 and position 3 are working different days of
     * the same cycle.
     *
     * @return list<RotationPoolMember>
     */
    public function findOrdered(Rotation $rotation): array
    {
        return $this->findBy(['rotation' => $rotation], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
