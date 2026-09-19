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

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;

/**
 * ONE PERSON IN A ROTATION'S POOL, AND WHERE THEY STAND IN THE RING.
 *
 * A JOIN ENTITY AND NOT A MANY-TO-MANY, because the order is the plan. Each
 * person enters the ring one day later than the last, so position 0 and
 * position 3 are working different days of the same cycle; a collection whose
 * order the database is free to return differently would quietly re-plan the
 * park between two page loads, and nothing would look wrong.
 *
 * BEING IN THE POOL IS NOT BEING POSTED THERE. The posting — station, person,
 * since — is the AREA's, and this is not a second copy of it: a pool may be
 * smaller than the postings (somebody on long leave is taken out of the ring
 * without being unposted) and a per-team ring has a pool and no station at
 * all. What the two must not do is disagree silently, which is why the
 * Configure page prints "5 of the 5 posted here" and says when they differ.
 */
#[ORM\Entity(repositoryClass: RotationPoolMemberRepository::class)]
#[ORM\Table(name: 'roster_rotation_pool')]
#[ORM\UniqueConstraint(name: 'uniq_roster_pool_rotation_person', columns: ['rotation_id', 'person_id'])]
class RotationPoolMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Rotation::class, inversedBy: 'pool')]
    #[ORM\JoinColumn(name: 'rotation_id', nullable: false, onDelete: 'CASCADE')]
    private Rotation $rotation;

    /**
     * WHO. The contract, not a class: the account is the installation's, and
     * whoever owns it states the resolution. A person deleted outright takes
     * their place in the ring with them — which is right, and rare: people
     * are deactivated, not deleted.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $person;

    /** Where they enter the ring, zero-based. */
    #[ORM\Column]
    private int $position;

    public function __construct(Rotation $rotation, UserInterface $person, int $position)
    {
        $this->rotation = $rotation;
        $this->person = $person;
        $this->position = $position;
        $rotation->addToPool($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRotation(): Rotation
    {
        return $this->rotation;
    }

    public function getPerson(): UserInterface
    {
        return $this->person;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
