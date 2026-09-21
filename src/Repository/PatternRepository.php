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
use Uhifadhi\Roster\Entity\Pattern;

/**
 * THE CYCLES AN AREA FILLS FROM. An area that has built none is an area
 * that fills by hand, which is a state and not a gap.
 *
 * @extends ServiceEntityRepository<Pattern>
 */
final class PatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pattern::class);
    }

    /**
     * @return list<Pattern>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        /** @var list<Pattern> $patterns */
        $patterns = $this->findBy(['area' => $area], ['id' => 'ASC']);

        return $patterns;
    }

    public function findOneByUuid(AreaOfInterest $area, Uuid $uuid): ?Pattern
    {
        return $this->findOneBy(['area' => $area, 'uuid' => $uuid]);
    }
}
