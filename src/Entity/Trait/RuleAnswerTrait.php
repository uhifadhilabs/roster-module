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

namespace Uhifadhi\Roster\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RuleValue;

/**
 * ONE RULE'S ANSWER, AS A ROW STORES IT — shared by the area's rule and by
 * a station's exception to it, because "what this rule says" is the same
 * question wherever it is asked.
 *
 * TWO SHAPES, AND EXACTLY ONE OF THEM FILLED. A measured rule stores the
 * number somebody typed and the unit they picked; a chosen rule stores the
 * case they picked and nothing else. Columns for both, nullable, and the
 * kind decides which pair is the answer — rather than a number column
 * holding an index into a list of words, which is how an answer stops
 * being readable in the database it is stored in.
 */
trait RuleAnswerTrait
{
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $value = null;

    #[ORM\Column(length: 16, nullable: true, enumType: RuleUnit::class)]
    private ?RuleUnit $unit = null;

    /** The case a chosen rule picked; null on every measured rule. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $choice = null;

    abstract public function getKind(): RuleKind;

    /**
     * The pair, as one object — what everything reads and nothing reassembles.
     *
     * @throws \LogicException when the rule is chosen rather than measured
     */
    public function getValue(): RuleValue
    {
        if (null === $this->value || null === $this->unit) {
            throw new \LogicException(\sprintf('"%s" holds a choice, not a measurement.', $this->getKind()->label()));
        }

        return new RuleValue($this->value, $this->unit);
    }

    /**
     * @throws \LogicException when the rule is measured rather than chosen
     */
    public function getChoice(): RuleChoiceInterface
    {
        if (null === $this->choice) {
            throw new \LogicException(\sprintf('"%s" holds a measurement, not a choice.', $this->getKind()->label()));
        }

        return $this->getKind()->choiceOf($this->choice);
    }

    /**
     * @throws \InvalidArgumentException when the unit cannot measure this kind
     */
    public function set(RuleValue $value): static
    {
        $checked = $this->getKind()->valueOf($value->value, $value->unit);

        $this->value = $checked->value;
        $this->unit = $checked->unit;
        $this->choice = null;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException when this rule does not offer that answer
     */
    public function choose(RuleChoiceInterface $choice): static
    {
        // A BACKED ENUM'S VALUE IS int|string TO THE LANGUAGE and a string
        // to every rule this module has: the cast is the interface's
        // promise written where the compiler can see it.
        $this->choice = (string) $this->getKind()->choiceOf((string) $choice->value)->value;
        $this->value = null;
        $this->unit = null;

        return $this;
    }
}
