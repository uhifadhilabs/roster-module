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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE FOUR COLUMNS THE ROSTER OWNS ON A STATION — and not one more.
 *
 * RULED 2026-09-18: a station is a place and the place is the AREA's. Its
 * name, kind, point, call sign, zone, opening and its postings all moved out
 * of this module. What stayed is the WATCH: which named shifts the post
 * expects, how long its silence may run, how long before that silence is
 * offline, and how close a ping has to be for a claim of "at post" to read as
 * verified.
 *
 * SO THIS IS A CONTRIBUTION, NOT A REGISTRY. It is a row hanging off somebody
 * else's record, drawn on the area's own station record and Stations configure
 * card as well as on this module's Configure page — one place it is edited,
 * several doors onto it. Deleting the roster module leaves the station whole.
 *
 * A POST WITH NO ROW HERE HAS NO WATCH, and that is a legal state with
 * consequences the design is explicit about: it is never counted, never late
 * and never a hole. Only offline, which is a fact about a post nobody has
 * heard from and not a complaint about a rota nobody wrote.
 *
 * A POST WITH A ROW AND AN EMPTY `expects` IS THE SAME THING SAID LOUDER — it
 * has been looked at and declared to run nothing. The row then still carries
 * the silence window that decides when its quiet becomes offline, which is
 * exactly why an outpost reached once a fortnight needs one.
 */
#[ORM\Entity(repositoryClass: StationWatchRepository::class)]
#[ORM\Table(name: 'roster_station_watch')]
#[ORM\UniqueConstraint(name: 'uniq_roster_watch_station', columns: ['station_id'])]
#[ORM\HasLifecycleCallbacks]
class StationWatch
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * ONE ROW PER STATION. The watch lives and dies with the post, because it
     * describes nothing else — but the post does not die with the watch, which
     * is the whole point of the ruling.
     */
    #[ORM\OneToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    /**
     * WHICH NAMED SHIFTS THIS POST EXPECTS, as keys from the area's own list.
     * Empty is legal and means "declared to run nothing".
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $expects = [];

    /** How long the post may go quiet before it reads as late. */
    #[ORM\Column(name: 'silence_window_minutes')]
    private int $silenceWindowMinutes;

    /** How long before late becomes offline. Always longer than the window above. */
    #[ORM\Column(name: 'offline_after_minutes')]
    private int $offlineAfterMinutes;

    /**
     * How close a ping has to be for a claim of "at post" to read as verified.
     *
     * IT NEVER HIDES ANYTHING. A claim whose pings fall outside it is shown
     * and flagged as unverified — never corrected, and never turned into an
     * absence. Changing it re-derives every past day, because a state is
     * never stored.
     */
    #[ORM\Column(name: 'catchment_metres')]
    private int $catchmentMetres;

    public function __construct(
        Station $station,
        int $silenceWindowMinutes,
        int $offlineAfterMinutes,
        int $catchmentMetres,
    ) {
        $this->station = $station;
        $this->silenceWindowMinutes = $silenceWindowMinutes;
        $this->offlineAfterMinutes = $offlineAfterMinutes;
        $this->catchmentMetres = $catchmentMetres;
        $this->guardThresholds();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    /**
     * @return list<string>
     */
    public function getExpects(): array
    {
        return $this->expects;
    }

    /**
     * @param list<string> $shiftKeys
     */
    public function expect(array $shiftKeys): static
    {
        $this->expects = array_values(array_unique($shiftKeys));

        return $this;
    }

    public function expectsNothing(): bool
    {
        return [] === $this->expects;
    }

    public function getSilenceWindowMinutes(): int
    {
        return $this->silenceWindowMinutes;
    }

    public function getOfflineAfterMinutes(): int
    {
        return $this->offlineAfterMinutes;
    }

    public function setThresholds(int $silenceWindowMinutes, int $offlineAfterMinutes): static
    {
        $this->silenceWindowMinutes = $silenceWindowMinutes;
        $this->offlineAfterMinutes = $offlineAfterMinutes;
        $this->guardThresholds();

        return $this;
    }

    public function getCatchmentMetres(): int
    {
        return $this->catchmentMetres;
    }

    public function setCatchmentMetres(int $catchmentMetres): static
    {
        if ($catchmentMetres < 1) {
            throw new \InvalidArgumentException('A catchment is a distance from the post, so it is at least one metre. Zero would flag everybody standing in the compound.');
        }

        $this->catchmentMetres = $catchmentMetres;

        return $this;
    }

    /**
     * OFFLINE HAS TO COME AFTER LATE, or a post would go straight from
     * reporting to offline and "late" would name nothing — which is the state
     * that exists precisely so somebody can pick up a radio before it becomes
     * the other one.
     */
    private function guardThresholds(): void
    {
        if ($this->silenceWindowMinutes < 1) {
            throw new \InvalidArgumentException('A silence window is at least one minute; zero would make every post late the moment it reported.');
        }

        if ($this->offlineAfterMinutes <= $this->silenceWindowMinutes) {
            throw new \InvalidArgumentException(\sprintf('Offline (%d min) has to come after late (%d min), or a post would never read as late at all.', $this->offlineAfterMinutes, $this->silenceWindowMinutes));
        }
    }
}
