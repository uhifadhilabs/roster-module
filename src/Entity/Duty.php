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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\DutyState;
use Uhifadhi\Roster\Repository\DutyRepository;

/**
 * ONE DUTY — one watch, at one station, on one day, for one person.
 *
 * A PLAN AND NEVER AN OBSERVATION. It says a ranger is due at a post on the
 * day watch; it does not say they are there. The one field that could tempt
 * anybody otherwise — an "attended" flag — is deliberately absent, and the
 * attendance sheet it would have grown into is the design's one rejected
 * frame. Who was actually there is derived from their own check-in and the
 * pings that followed it, both the area's, computed on read and stored
 * nowhere.
 *
 * THE DAY IS THE DAY THE WATCH BEGINS. A night watch runs 18:00 to 06:00 and
 * belongs to the calendar day it starts on — one duty, drawn as two blocks on
 * a day board. Splitting it at midnight would make "who is on tonight" a
 * question about two dates.
 *
 * A HOLE IS NOT A DUTY. An unfilled slot is the shortfall between what the
 * rotation asks for and the duties that exist, computed when something asks;
 * a duty with no person would be a row that means "nobody", which every count
 * in the module would then have to remember to exclude.
 *
 * THE SHIFT IS A KEY, NOT A RELATION. It names one of the area's own
 * {@see Shift} records, and it is stored as the key rather than a foreign key
 * for the reason a shift is closed and never deleted: the duty keeps the word
 * that described it even if the list moves on. The key is issued once and
 * never edited, which is what makes that safe.
 */
#[ORM\Entity(repositoryClass: DutyRepository::class)]
#[ORM\Table(name: 'roster_duty')]
#[ORM\Index(name: 'idx_roster_duty_area_day', columns: ['area_id', 'on_day'])]
#[ORM\Index(name: 'idx_roster_duty_station_day', columns: ['station_id', 'on_day'])]
#[ORM\Index(name: 'idx_roster_duty_person_day', columns: ['person_id', 'on_day'])]
#[ORM\UniqueConstraint(name: 'uniq_roster_duty_person_station_shift_day', columns: ['person_id', 'station_id', 'shift_key', 'on_day'])]
#[ORM\HasLifecycleCallbacks]
class Duty
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * Carried on the duty itself rather than reached through the station,
     * because every read in this module is confined to one area and a join
     * per row to learn which one is a join nobody needs.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** Where the watch is stood. The area's record; this module owns none. */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    /** Who is due. The contract, resolved by whoever owns the account class. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $person;

    /** One of the area's own shift keys. */
    #[ORM\Column(name: 'shift_key', length: 32)]
    private string $shiftKey;

    /** The calendar day the watch begins on. */
    #[ORM\Column(name: 'on_day', type: 'date_immutable')]
    private \DateTimeImmutable $onDay;

    /**
     * WHICH STANDING ROTATION GENERATED THIS, or null for a duty a person put
     * there by hand. SET NULL rather than cascade: deleting a rotation must
     * not silently empty a month that people have already been told about.
     */
    #[ORM\ManyToOne(targetEntity: Rotation::class)]
    #[ORM\JoinColumn(name: 'rotation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Rotation $rotation = null;

    #[ORM\Column(length: 16, enumType: DutyState::class)]
    private DutyState $state = DutyState::Planned;

    /** Free text, rarely used. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct(
        AreaOfInterest $area,
        Station $station,
        UserInterface $person,
        string $shiftKey,
        \DateTimeImmutable $onDay,
    ) {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->station = $station;
        $this->person = $person;
        $this->shiftKey = $shiftKey;
        // A duty is a whole day's plan. A time on it would make two duties for
        // the same watch look different to every comparison in the module.
        $this->onDay = $onDay->setTime(0, 0);
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

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getPerson(): UserInterface
    {
        return $this->person;
    }

    public function getShiftKey(): string
    {
        return $this->shiftKey;
    }

    public function getOnDay(): \DateTimeImmutable
    {
        return $this->onDay;
    }

    public function getRotation(): ?Rotation
    {
        return $this->rotation;
    }

    public function generatedBy(?Rotation $rotation): static
    {
        $this->rotation = $rotation;

        return $this;
    }

    /**
     * THE WATCH CHANGES HANDS — the same row, deliberately.
     *
     * A swap that deleted one duty and wrote another would lose the row's
     * identity, and everything pointing at it — the swap that agreed the
     * move, most of all — would be pointing at nothing. The duty is the
     * watch; who stands it is a field on it.
     */
    /**
     * THE SAME DAY, ON A DIFFERENT WATCH — the sheet's "change the
     * shift". The person, the station and the day all stand; only which
     * window they are on moves.
     */
    public function changeShift(string $shiftKey): static
    {
        $this->shiftKey = $shiftKey;

        return $this;
    }

    public function reassignTo(UserInterface $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getState(): DutyState
    {
        return $this->state;
    }

    public function setState(DutyState $state): static
    {
        $this->state = $state;

        return $this;
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
}
