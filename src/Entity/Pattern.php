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

namespace Uhifadhi\Roster\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\PatternRepository;

/**
 * A CYCLE THIS AREA FILLS A STATION FROM — one object, at area level.
 *
 * RULED 20 sep. Owner: "users should be the ones defining the fill
 * patterns, not the system forcing the user." THE PRODUCT SHIPS NO
 * PATTERNS. A pattern is a cycle somebody built out of this area's own
 * shifts plus off, and an area that has built none fills nothing — which
 * is a real state and not a missing setup step.
 *
 * ONE OBJECT AT AREA LEVEL, RULED 21 sep ("why not"). It is applied to
 * stations from the Week sheet's fill row, so a card can say "running at
 * three stations" and mean it. What makes one shared object safe to edit
 * is the next rule.
 *
 * EDITING CHANGES FUTURE FILLS ONLY. No day already planned moves, at any
 * station running it, and a day somebody edited by hand is never touched.
 * That is why a pattern may be shared at all: the alternative — one copy
 * per station — is three objects going quietly out of step, and the
 * alternative to THAT is an edit that moves people who are already
 * rostered.
 *
 * IT HAS NO NAME COLUMN, deliberately (ruled 21 sep). The name is derived
 * from the cycle by {@see \Uhifadhi\Roster\Service\PatternNamer} on every
 * read, so renaming a shift renames every pattern built from it and the
 * register, the fill row and the sheet cannot disagree.
 */
#[ORM\Entity(repositoryClass: PatternRepository::class)]
#[ORM\Table(name: 'roster_pattern')]
#[ORM\UniqueConstraint(name: 'uniq_roster_pattern_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_roster_pattern_area', columns: ['area_id'])]
#[ORM\HasLifecycleCallbacks]
class Pattern
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * The cycle, one entry per day: a shift key from this area's own list,
     * or {@see Cycle::OFF}. The same shape a ring has always been stored
     * in, so the generator reads a pattern exactly as it read a rotation.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $cycle;

    public function __construct(AreaOfInterest $area, Cycle $cycle)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->cycle = $cycle->toStored();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getCycle(): Cycle
    {
        return Cycle::fromStored($this->cycle);
    }

    public function setCycle(Cycle $cycle): static
    {
        $this->cycle = $cycle->toStored();

        return $this;
    }

    /** How many days round it goes — what the editor prints beside the name. */
    public function length(): int
    {
        return $this->getCycle()->length();
    }
}
