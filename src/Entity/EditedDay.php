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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Repository\EditedDayRepository;

/**
 * A DAY A PERSON HAS TOUCHED, AND THEREFORE A DAY THE GENERATOR LEAVES ALONE.
 *
 * WHY THIS IS A ROW AND NOT A FLAG ON A DUTY. "An edited day is never
 * overwritten by a later run" is ruled, and a flag cannot carry it: the most
 * common edit is TAKING SOMEBODY OFF a watch, and a flag on a deleted duty
 * goes with the duty. The next run would then find nothing there, conclude
 * the day was never generated, and put the person the duty officer removed
 * straight back on. The mark has to survive the thing it protects, so it is
 * a mark on the DAY.
 *
 * ONE ROW PER STATION PER DAY, not per rotation: two rotations may reach the
 * same post (a team's tour standing alongside the post's own ring) and the
 * protection is about the day a person read and changed, not about which
 * machine had written it.
 *
 * IT RECORDS WHO AND WHEN, because the one question anybody asks of a day
 * that stopped following the pattern is who changed it.
 */
#[ORM\Entity(repositoryClass: EditedDayRepository::class)]
#[ORM\Table(name: 'roster_edited_day')]
/*
 * TWO PARTIAL UNIQUES, NOT ONE. Postgres treats NULLs as distinct, so a
 * single unique over (station, day, person) would let the station-wide
 * mark — the one with no person — be written twice for the same day.
 */
#[ORM\UniqueConstraint(name: 'uniq_roster_edited_day_station_day', columns: ['station_id', 'on_day'], options: ['where' => '(person_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_roster_edited_day_station_day_person', columns: ['station_id', 'on_day', 'person_id'], options: ['where' => '(person_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_roster_edited_day_person', columns: ['person_id'])]
class EditedDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    #[ORM\Column(name: 'on_day', type: 'date_immutable')]
    private \DateTimeImmutable $onDay;

    /**
     * Who changed it. Nullable, and SET NULL on delete, because the fact that
     * a day was edited must outlive the account that edited it — losing the
     * name is a smaller loss than the generator waking up and overwriting the
     * day.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'edited_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $editedBy = null;

    #[ORM\Column(name: 'edited_at')]
    private \DateTimeImmutable $editedAt;

    /**
     * WHOSE DAY IT IS. Null is the station-wide mark the generator has
     * always read — "nobody touches this station on this day" — and a
     * person is the sheet's own mark on one cell, which is the only thing
     * a people-down sheet can honestly draw.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $person = null;

    /**
     * WHETHER THE HAND LEFT THE DAY OFF OR LEFT IT OPEN. Both are a day
     * with no duty on it, and they are not the same fact: one is a ranger
     * deliberately stood down and draws nothing, the other a seat the
     * station still needs somebody on and draws as unfilled.
     */
    #[ORM\Column(name: 'left_off')]
    private bool $leftOff = false;

    public function __construct(Station $station, \DateTimeImmutable $onDay, ?UserInterface $editedBy, \DateTimeImmutable $editedAt, ?UserInterface $person = null, bool $leftOff = false)
    {
        $this->station = $station;
        $this->onDay = $onDay->setTime(0, 0);
        $this->editedBy = $editedBy;
        $this->editedAt = $editedAt;
        $this->person = $person;
        $this->leftOff = $leftOff;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getOnDay(): \DateTimeImmutable
    {
        return $this->onDay;
    }

    public function getEditedBy(): ?UserInterface
    {
        return $this->editedBy;
    }

    public function getEditedAt(): \DateTimeImmutable
    {
        return $this->editedAt;
    }

    /** Whose day this mark is about; null where it is the whole station's. */
    public function getPerson(): ?UserInterface
    {
        return $this->person;
    }

    public function isLeftOff(): bool
    {
        return $this->leftOff;
    }

    public function leaveOff(bool $leftOff): static
    {
        $this->leftOff = $leftOff;

        return $this;
    }

    /** A second edit on the same day moves the clock and the name, not the row. */
    public function touch(?UserInterface $editedBy, \DateTimeImmutable $editedAt): static
    {
        $this->editedBy = $editedBy;
        $this->editedAt = $editedAt;

        return $this;
    }
}
