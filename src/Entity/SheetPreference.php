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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Repository\SheetPreferenceRepository;

/**
 * HOW ONE PERSON LIKES THE SHEET — how many weeks they read at a time,
 * and which stations they keep folded.
 *
 * RULED 21 sep: both are remembered per person. A duty officer who works
 * two stations folds the other ten once, not every morning; a planner
 * who reads a month at a time gets a month when they come back.
 *
 * PER PERSON AND PER AREA. An area with twelve stations and one with two
 * are not read the same way, and a fold list keyed by station code would
 * be nonsense carried between them.
 *
 * NOT IN THE BROWSER. Local storage would lose it on the second device,
 * and the sheet's window is also what the fill row and the band count
 * against — a preference the server cannot see is a preference the page
 * has to be re-taught on every request.
 */
#[ORM\Entity(repositoryClass: SheetPreferenceRepository::class)]
#[ORM\Table(name: 'roster_sheet_preference')]
#[ORM\UniqueConstraint(name: 'uniq_roster_sheet_preference_person_area', columns: ['person_id', 'area_id'])]
class SheetPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $person;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    #[ORM\Column]
    private int $weeks = SheetWindow::DEFAULT_WEEKS;

    /**
     * THE STATIONS THIS PERSON KEEPS FOLDED, by call sign.
     *
     * OPEN IS THE DEFAULT AND CLOSED IS WHAT IS STORED, so a station
     * added to the area tomorrow is open tomorrow rather than hidden by
     * a preference written before it existed.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $folded = [];

    public function __construct(UserInterface $person, AreaOfInterest $area)
    {
        $this->person = $person;
        $this->area = $area;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): UserInterface
    {
        return $this->person;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getWeeks(): int
    {
        return $this->weeks;
    }

    /** Anything that is not one of the three is ignored: there is no three. */
    public function readWeeks(int $weeks): static
    {
        if (\in_array($weeks, SheetWindow::WEEKS, true)) {
            $this->weeks = $weeks;
        }

        return $this;
    }

    /** @return list<string> */
    public function getFolded(): array
    {
        return $this->folded;
    }

    /**
     * @param list<string> $folded
     */
    public function fold(array $folded): static
    {
        $this->folded = array_values(array_unique($folded));

        return $this;
    }

    public function isFolded(string $station): bool
    {
        return \in_array($station, $this->folded, true);
    }
}
