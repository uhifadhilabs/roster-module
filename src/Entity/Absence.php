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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Repository\AbsenceRepository;

/**
 * SOMEBODY IS NOT AVAILABLE, FROM A DAY TO A DAY.
 *
 * WHY THIS MODULE HOLDS IT AT ALL, given that Team owns the person: the HOLE
 * an absence makes is this module's to show, and a hole that could not be
 * explained would be read as a planning failure. Five fields — person, from,
 * to, kind, recorded by — and nothing else.
 *
 * NO APPROVAL, RULED FOR v1. There is no request, no approver and no state
 * machine here. If approval is wanted it belongs to Team, which owns the
 * person's employment, and this record gains a read-only state chip and not
 * a workflow.
 *
 * INCLUSIVE AT BOTH ENDS. "From monday to friday" means five days off,
 * because that is what everybody who writes it down means, and an exclusive
 * end date is the off-by-one that quietly rosters somebody on their last day
 * of leave.
 *
 * A REST DAY IS NOT AN ABSENCE. A ring that stands somebody down produces no
 * state at all; this is a departure from what the ring already says.
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'roster_absence')]
#[ORM\Index(name: 'idx_roster_absence_person_span', columns: ['person_id', 'starts_on', 'ends_on'])]
#[ORM\HasLifecycleCallbacks]
class Absence
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $person;

    #[ORM\Column(name: 'starts_on', type: 'date_immutable')]
    private \DateTimeImmutable $startsOn;

    /** The last day of the absence, inclusive. */
    #[ORM\Column(name: 'ends_on', type: 'date_immutable')]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(length: 16, enumType: AbsenceKind::class)]
    private AbsenceKind $kind;

    /**
     * Who wrote it down. Nullable and SET NULL, for the reason an edited day's
     * name is: the absence outlives the account that recorded it, and losing
     * the name is a smaller loss than losing the absence.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $recordedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct(
        AreaOfInterest $area,
        UserInterface $person,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        AbsenceKind $kind,
        ?UserInterface $recordedBy = null,
    ) {
        $startsOn = $startsOn->setTime(0, 0);
        $endsOn = $endsOn->setTime(0, 0);

        if ($endsOn < $startsOn) {
            throw new \InvalidArgumentException(\sprintf('An absence ends on or after the day it starts; %s comes before %s.', $endsOn->format('Y-m-d'), $startsOn->format('Y-m-d')));
        }

        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->person = $person;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->kind = $kind;
        $this->recordedBy = $recordedBy;
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

    public function getPerson(): UserInterface
    {
        return $this->person;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getKind(): AbsenceKind
    {
        return $this->kind;
    }

    public function getRecordedBy(): ?UserInterface
    {
        return $this->recordedBy;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** Both ends inclusive. */
    public function covers(\DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->startsOn && $day <= $this->endsOn;
    }
}
