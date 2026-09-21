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
use Uhifadhi\Roster\Entity\Trait\RuleAnswerTrait;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
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
    use RuleAnswerTrait;
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

    /**
     * THE ANSWER IS EITHER SHAPE, and the row takes whichever the rule has.
     * A single constructor rather than two: "this rule, for this
     * station, says this" is one act, and a caller that had to know in
     * advance which of two verbs a kind wanted would be carrying the
     * enum's own knowledge around with it.
     *
     * @throws \InvalidArgumentException when the answer is not one this rule takes
     */
    public function __construct(Station $station, RuleKind $kind, RuleValue|RuleChoiceInterface $answer)
    {
        $this->uuid = Uuid::v7();
        $this->station = $station;
        $this->kind = $kind;

        if ($answer instanceof RuleValue) {
            $this->set($answer);
        } else {
            $this->choose($answer);
        }
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
}
