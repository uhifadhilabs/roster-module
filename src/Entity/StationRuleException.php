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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Repository\StationRuleExceptionRepository;

/**
 * ONE RULE ONE STATION DOES DIFFERENTLY — and the whole of what makes the
 * area's five a default rather than a law.
 *
 * RULED 20 sep: any rule, not just hours. RULED 21 sep: an exception is a
 * property of a STATION, so it lives on that station's own row and never
 * in a fourth card at the bottom of the page — the eleven stations with
 * nothing to say are not made to say it.
 *
 * REMOVING IT IS HOW A STATION GOES BACK TO FOLLOWING THE AREA. There is
 * no "same as the area" value to store: the absence of a row IS that
 * answer, which is why the row's only other control is a cross.
 */
#[ORM\Entity(repositoryClass: StationRuleExceptionRepository::class)]
#[ORM\Table(name: 'roster_station_rule_exception')]
#[ORM\UniqueConstraint(name: 'uniq_roster_exception_station_kind', columns: ['station_id', 'kind'])]
#[ORM\UniqueConstraint(name: 'uniq_roster_station_rule_exception_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_roster_station_rule_exception_station', columns: ['station_id'])]
#[ORM\HasLifecycleCallbacks]
class StationRuleException
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    /**
     * CASCADE, matching everything else this module keys by station: a
     * station deleted outright takes the exceptions that described it,
     * because a rule for a place that does not exist can never apply and
     * would show on every count as an exception nobody can find.
     */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    #[ORM\Column(length: 32, enumType: RuleKind::class)]
    private RuleKind $kind;

    #[ORM\Column(type: 'float')]
    private float $value;

    #[ORM\Column(length: 16, enumType: RuleUnit::class)]
    private RuleUnit $unit;

    public function __construct(Station $station, RuleKind $kind, RuleValue $value)
    {
        $this->uuid = Uuid::v7();
        $this->station = $station;
        $this->kind = $kind;
        $this->set($value);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getKind(): RuleKind
    {
        return $this->kind;
    }

    public function getValue(): RuleValue
    {
        return new RuleValue($this->value, $this->unit);
    }

    /**
     * @throws \InvalidArgumentException when the unit cannot measure this kind
     */
    public function set(RuleValue $value): static
    {
        $checked = $this->kind->valueOf($value->value, $value->unit);

        $this->value = $checked->value;
        $this->unit = $checked->unit;

        return $this;
    }
}
