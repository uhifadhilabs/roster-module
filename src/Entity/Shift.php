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
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * ONE NAMED WINDOW A WATCH CAN BE STOOD IN — "day 06:00–18:00".
 *
 * ONE LIST FOR THE WHOLE AREA, and that is the ruling the shape follows.
 * Every rotation, every rota cell and every day-board block reads from it, so
 * a fifth shift is a fifth chip everywhere and nothing has to be redrawn. It
 * is an ENTITY and not config for the same reason a patrol type is: the list
 * is edited on a screen by somebody who runs the park, not by whoever last
 * deployed it. What the bundle's `roster.shifts` config holds is the
 * vocabulary a NEW AREA's list is seeded with — the starting point, never the
 * running value.
 *
 * THE KEY IS WHAT A DUTY STORES, so it is issued once and never edited. A
 * label is what people read and it may be renamed freely; a key that changed
 * would make every duty already written ambiguous about the window it was
 * stood in, and no later screen could recover the answer.
 *
 * CLOSED, NEVER DELETED. "A shift in use cannot be deleted, only closed" — a
 * closed shift keeps every duty that names it and simply stops being offered,
 * which is what lets a past month keep the words that describe it.
 *
 * A WINDOW MAY CROSS MIDNIGHT and that is not a defect to validate away: night
 * runs 18:00 to 06:00. A duty belongs to the calendar day its watch BEGINS on,
 * so a night watch is one duty and two blocks on a day board — drawing it as
 * one block would be a lie about the day it belongs to.
 */
#[ORM\Entity(repositoryClass: ShiftRepository::class)]
#[ORM\Table(name: 'roster_shift')]
#[ORM\UniqueConstraint(name: 'uniq_roster_shift_area_key', columns: ['area_id', 'shift_key'])]
#[ORM\HasLifecycleCallbacks]
class Shift
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** The list is the area's; a shift lives and dies with it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * What a duty stores. `shift_key` and not `key`, because `key` is
     * reserved in enough of the SQL dialects this has to survive.
     */
    #[ORM\Column(name: 'shift_key', length: 32)]
    private string $key;

    #[ORM\Column(length: 64)]
    private string $label;

    /** When the window opens, as HH:MM in the area's own clock. */
    #[ORM\Column(name: 'starts_at', length: 5)]
    private string $startsAt;

    /** When it closes. Earlier than {@see $startsAt} means it crosses midnight. */
    #[ORM\Column(name: 'ends_at', length: 5)]
    private string $endsAt;

    /** Where it sits in the chip row, and in every select this list fills. */
    #[ORM\Column]
    private int $position = 0;

    /** Closed, never deleted: the day it stopped being offered. */
    #[ORM\Column(name: 'closed_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * THE SLOT ON THE HOUSE PLATE PALETTE THIS SHIFT WAS GIVEN WHEN IT WAS
     * CREATED, and keeps on every surface.
     *
     * RULED 21 sep. A shift is given a colour at creation and the shift
     * row, the sheet, the calendar and the day board all read this stored
     * value — none of them decides from a POSITION in a list, which is
     * what made a colour change the day somebody reordered the shifts or
     * closed one.
     *
     * A SLOT AND NOT A COLOUR. The house owns the palette and ships
     * `[data-cat="1".."18"]`; this module stores which of the eighteen and
     * names no hue at all, so a theme change reaches every shift in the
     * product without a migration.
     */
    #[ORM\Column]
    private int $colour = self::FIRST_SLOT;

    /** The plate palette's first slot, and how many there are. */
    public const int FIRST_SLOT = 1;
    public const int SLOTS = 18;

    public function __construct(AreaOfInterest $area, string $key, string $label, string $startsAt, string $endsAt, int $position = 0, int $colour = self::FIRST_SLOT)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->key = $key;
        $this->label = $label;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->position = $position;
        $this->colour = self::slot($colour);
    }

    /** Which of the palette's slots this shift wears. */
    public function getColour(): int
    {
        return $this->colour;
    }

    public function recolour(int $colour): static
    {
        $this->colour = self::slot($colour);

        return $this;
    }

    /**
     * A SLOT THE PALETTE ACTUALLY HAS. A number outside it would render
     * with no colour at all — the house's fallback — which reads as a
     * shift nobody gave one rather than as a number somebody mistyped.
     */
    private static function slot(int $colour): int
    {
        return $colour >= self::FIRST_SLOT && $colour <= self::SLOTS ? $colour : self::FIRST_SLOT;
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

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function rename(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getStartsAt(): string
    {
        return $this->startsAt;
    }

    public function getEndsAt(): string
    {
        return $this->endsAt;
    }

    public function moveWindow(string $startsAt, string $endsAt): static
    {
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;

        return $this;
    }

    /** Whether the window runs past midnight into the next calendar day. */
    public function crossesMidnight(): bool
    {
        return $this->endsAt <= $this->startsAt;
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

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }

    public function close(\DateTimeImmutable $on): static
    {
        $this->closedAt ??= $on;

        return $this;
    }

    public function reopen(): static
    {
        $this->closedAt = null;

        return $this;
    }
}
