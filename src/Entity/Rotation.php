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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\RotationRepository;

/**
 * A STANDING ROTATION — the ring of days a post or a team repeats for ever,
 * and everything the generator needs to turn it into duties.
 *
 * THE RULED AUTHORING MODEL, WHOLE: a pattern generates, a day is edited. It
 * is the only one of the three drawn models in which a HOLE is detectable —
 * a weekly plan filled by hand has nothing to compare against, and daily
 * assignment cannot tell an empty station from one nobody needs. The cost is
 * stated and accepted: two objects to keep in step, and a stale rotation
 * describes a park that no longer exists.
 *
 * A ROTATION IS NOT A ROTA. There are no dates in it. What turns the ring into
 * a month is the anchor, the pool's order and the calendar, and the result is
 * {@see Duty} rows the generator writes — which is why editing a day never
 * touches the rotation, and changing the rotation never rewrites an edited
 * day.
 *
 * NOTHING HERE IS PRESENCE. A rotation says who is DUE. Who was actually there
 * is the ranger's own check-in and the pings that followed it, both the area's,
 * and no screen that edits this object writes one.
 *
 * THE POOL IS ORDERED, AND THE ORDER IS LOAD-BEARING: each person enters the
 * ring one day later than the last, so a five-day ring of two days, two nights
 * and one off staffs a post out of a pool of five for ever. That is why the
 * pool is a join entity with a position and not an unordered many-to-many —
 * a collection whose order the database is free to change would quietly
 * re-plan the park.
 */
#[ORM\Entity(repositoryClass: RotationRepository::class)]
#[ORM\Table(name: 'roster_rotation')]
#[ORM\HasLifecycleCallbacks]
class Rotation
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

    #[ORM\Column(length: 16, enumType: RotationScope::class)]
    private RotationScope $scope;

    /**
     * THE POST THIS RING BELONGS TO, for a per-post rotation, and null for a
     * per-team one.
     *
     * CASCADE, matching the station's own relation to its area: a station that
     * is deleted outright takes the ring that described it, because a rotation
     * for a post that does not exist can generate nothing and would show up on
     * every count as a rotation nobody can find. A CLOSED station is the
     * ordinary case and touches nothing — the area deactivates, it does not
     * delete.
     */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: true, onDelete: 'CASCADE')]
    private ?Station $station = null;

    /** What the squad is called, for a per-team rotation; null for a per-post one. */
    #[ORM\Column(name: 'team_name', length: 96, nullable: true)]
    private ?string $teamName = null;

    /**
     * The ring, one entry per day: a shift key from the area's own list, or
     * {@see Cycle::OFF}. Stored as JSON because nothing ever asks the database
     * a question about a single ring position.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $cycle;

    /**
     * DAY 1 OF THE RING. Every offset in the ring is counted from here, so
     * moving it re-plans the future — which is exactly what a duty officer
     * means when they shift a rotation by a day.
     */
    #[ORM\Column(name: 'anchored_on', type: 'date_immutable')]
    private \DateTimeImmutable $anchoredOn;

    /**
     * WHAT THE POST EXPECTS PER DAY, keyed by shift key — "day 2, night 2".
     *
     * NOT what the ring produces, and the difference is the whole point: the
     * ring produces whatever the pool is big enough for, and the shortfall
     * against this declaration IS the hole. A post whose pool has shrunk keeps
     * generating, keeps asking for two, and says so.
     *
     * @var array<string, int>
     */
    #[ORM\Column(name: 'slots_per_shift', type: Types::JSON)]
    private array $slotsPerShift;

    /**
     * The people the ring draws from, in the order they enter it.
     *
     * @var Collection<int, RotationPoolMember>
     */
    #[ORM\OneToMany(targetEntity: RotationPoolMember::class, mappedBy: 'rotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $pool;

    /**
     * WEEKDAYS THE WHOLE POST STANDS DOWN — ISO numbers, monday 1 to sunday 7.
     * "Two day watches, saturdays stood down" is a real shape and it is not
     * the ring's: the ring would have to be seven days long to express it, and
     * then it would stop being a two-on-one-off ring.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'stand_down_weekdays', type: Types::JSON)]
    private array $standDownWeekdays = [];

    #[ORM\Column(name: 'rest_rule', length: 32, enumType: RestRule::class)]
    private RestRule $restRule = RestRule::None;

    /**
     * HOW FAR AHEAD THE GENERATOR RUNS, in days from the day it runs.
     *
     * Days and not a preset, although the editor offers three ("six weeks",
     * "three months", "end of the year"): a preset is a way of choosing a
     * number, and storing the preset would mean "end of the year" silently
     * meant something different every january.
     */
    #[ORM\Column(name: 'horizon_days')]
    private int $horizonDays;

    /**
     * THE LAST DAY THE GENERATOR HAS WRITTEN, or null before its first run.
     * What the Configure page's "Generated to wed 28 oct" reads, and what the
     * next run starts after.
     */
    #[ORM\Column(name: 'generated_through', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $generatedThrough = null;

    /**
     * A rotation that has been stood down. Its duties stay — they were real
     * plans — and it generates nothing further.
     */
    #[ORM\Column]
    private bool $active = true;

    /**
     * @param array<string, int> $slotsPerShift
     */
    public function __construct(
        AreaOfInterest $area,
        RotationScope $scope,
        Cycle $cycle,
        \DateTimeImmutable $anchoredOn,
        array $slotsPerShift,
        int $horizonDays,
    ) {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->scope = $scope;
        $this->cycle = $cycle->toStored();
        // A rotation is planned in whole days, so the anchor is a day. Keeping
        // a time on it would make every offset depend on the hour somebody
        // happened to save the form in.
        $this->anchoredOn = $anchoredOn->setTime(0, 0);
        $this->slotsPerShift = $slotsPerShift;
        $this->horizonDays = $horizonDays;
        $this->pool = new ArrayCollection();
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

    public function getScope(): RotationScope
    {
        return $this->scope;
    }

    public function getStation(): ?Station
    {
        return $this->station;
    }

    /** Attach the ring to a post. Only a per-post rotation has one. */
    public function standAt(Station $station): static
    {
        $this->station = $station;
        $this->scope = RotationScope::Post;
        $this->teamName = null;

        return $this;
    }

    public function getTeamName(): ?string
    {
        return $this->teamName;
    }

    /** Give the ring to a squad instead. A team's cycle travels with them. */
    public function carriedBy(string $teamName): static
    {
        $this->teamName = $teamName;
        $this->scope = RotationScope::Team;
        $this->station = null;

        return $this;
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

    public function getAnchoredOn(): \DateTimeImmutable
    {
        return $this->anchoredOn;
    }

    public function setAnchoredOn(\DateTimeImmutable $anchoredOn): static
    {
        $this->anchoredOn = $anchoredOn->setTime(0, 0);

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getSlotsPerShift(): array
    {
        return $this->slotsPerShift;
    }

    /**
     * @param array<string, int> $slotsPerShift
     */
    public function setSlotsPerShift(array $slotsPerShift): static
    {
        $this->slotsPerShift = $slotsPerShift;

        return $this;
    }

    /** How many people this rotation asks for on one shift of one day. */
    public function slotsFor(string $shiftKey): int
    {
        return $this->slotsPerShift[$shiftKey] ?? 0;
    }

    /**
     * @return Collection<int, RotationPoolMember>
     */
    public function getPool(): Collection
    {
        return $this->pool;
    }

    public function addToPool(RotationPoolMember $member): static
    {
        if (!$this->pool->contains($member)) {
            $this->pool->add($member);
        }

        return $this;
    }

    public function removeFromPool(RotationPoolMember $member): static
    {
        $this->pool->removeElement($member);

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getStandDownWeekdays(): array
    {
        return $this->standDownWeekdays;
    }

    /**
     * @param list<int> $weekdays ISO numbers, monday 1 to sunday 7
     */
    public function setStandDownWeekdays(array $weekdays): static
    {
        $this->standDownWeekdays = array_values(array_unique($weekdays));

        return $this;
    }

    public function standsDownOn(\DateTimeImmutable $date): bool
    {
        return \in_array((int) $date->format('N'), $this->standDownWeekdays, true);
    }

    public function getRestRule(): RestRule
    {
        return $this->restRule;
    }

    public function setRestRule(RestRule $restRule): static
    {
        $this->restRule = $restRule;

        return $this;
    }

    public function getHorizonDays(): int
    {
        return $this->horizonDays;
    }

    public function setHorizonDays(int $horizonDays): static
    {
        $this->horizonDays = $horizonDays;

        return $this;
    }

    public function getGeneratedThrough(): ?\DateTimeImmutable
    {
        return $this->generatedThrough;
    }

    public function setGeneratedThrough(?\DateTimeImmutable $through): static
    {
        $this->generatedThrough = $through?->setTime(0, 0);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
