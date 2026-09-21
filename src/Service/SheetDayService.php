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

namespace Uhifadhi\Roster\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Repository\EditedDayRepository;

/**
 * ONE DAY, CHANGED BY HAND — every item on the sheet's by-hand menu.
 *
 * EVERY ONE OF THESE LEAVES A MARK, and that is the point of the class
 * rather than of five controller actions. A day somebody decided is a
 * day the fill must never decide again, and a mark that were left off
 * by one of five call sites would be a day quietly overwritten the next
 * night.
 *
 * THE MARK SURVIVES THE THING IT PROTECTS. The most common edit is
 * taking somebody OFF a watch, and a flag on a deleted duty goes with
 * the duty — so it is a row about the DAY, carrying whose day it is and
 * which of the two empty answers the hand meant.
 *
 * AND CLEARING THE MARK IS AN ACT OF ITS OWN. Removing it hands the day
 * back to the pattern, which is exactly what the menu's last item says
 * it does, and nothing else on the menu does it by accident.
 */
final readonly class SheetDayService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EditedDayRepository $marks,
        private ClockInterface $clock,
    ) {
    }

    /** THE SHIFT ON A DAY, CHANGED. */
    public function changeShift(Duty $duty, string $shiftKey, ?UserInterface $by): Duty
    {
        $duty->changeShift($shiftKey);
        $this->mark($duty->getStation(), $duty->getOnDay(), $duty->getPerson(), false, $by);

        $this->entityManager->flush();

        return $duty;
    }

    /**
     * THE DAY GIVEN TO SOMEBODY ELSE — both people's days were decided
     * by hand.
     *
     * THE ORIGIN IS AN ABSENCE, not a gap declared by hand: the person
     * is simply no longer working that day. Whether that leaves a HOLE
     * is the station's ring to answer, and where it wanted cover the
     * sheet outlines it and the station head counts one more.
     */
    public function moveTo(Duty $duty, UserInterface $person, ?UserInterface $by): Duty
    {
        $from = $duty->getPerson();
        $duty->reassignTo($person);

        $this->mark($duty->getStation(), $duty->getOnDay(), $from, true, $by);
        $this->mark($duty->getStation(), $duty->getOnDay(), $person, false, $by);

        $this->entityManager->flush();

        return $duty;
    }

    /**
     * THE RANGER GIVEN THE DAY OFF. The watch goes and the day draws
     * nothing — which is not the same as the seat still needing
     * somebody, so the mark says so.
     */
    public function giveTheDayOff(Duty $duty, ?UserInterface $by): void
    {
        $this->mark($duty->getStation(), $duty->getOnDay(), $duty->getPerson(), true, $by);
        $this->entityManager->remove($duty);

        $this->entityManager->flush();
    }

    /**
     * THE DAY MARKED UNFILLED. The watch goes and the seat stays open,
     * in the alarm ink, until somebody decides otherwise.
     */
    public function markUnfilled(Duty $duty, ?UserInterface $by): void
    {
        $this->mark($duty->getStation(), $duty->getOnDay(), $duty->getPerson(), false, $by);
        $this->entityManager->remove($duty);

        $this->entityManager->flush();
    }

    /** AND HANDING THE DAY BACK TO THE PATTERN. */
    public function clearTheMark(Station $station, \DateTimeImmutable $onDay, UserInterface $person): void
    {
        $mark = $this->marks->findOneForPerson($station, $onDay, $person);
        if (null === $mark) {
            return;
        }

        $this->entityManager->remove($mark);
        $this->entityManager->flush();
    }

    /**
     * ONE RANGER'S DAY, MARKED, FOR ANYBODY WHO CHANGES ONE.
     *
     * THE RULE IS HERE SO THERE IS ONE OF IT. A swap accepted on the
     * handset changes two people's days exactly as the menu does, and a
     * second copy of "which row, whose day, absence or gap" is a second
     * chance to mark the whole station by leaving the ranger off.
     *
     * THE CALLER OWNS THE FLUSH: a swap writes two marks and moves two
     * duties, and that is one act.
     */
    public function markByHand(Station $station, \DateTimeImmutable $onDay, UserInterface $person, bool $leftOff, ?UserInterface $by, ?\DateTimeImmutable $at = null): void
    {
        $this->mark($station, $onDay, $person, $leftOff, $by, $at);
    }

    /**
     * ONE RANGER'S DAY, MARKED — written once and touched after that, so
     * two edits to one day are one mark with the later name on it.
     */
    private function mark(Station $station, \DateTimeImmutable $onDay, UserInterface $person, bool $leftOff, ?UserInterface $by, ?\DateTimeImmutable $at = null): void
    {
        $at ??= \DateTimeImmutable::createFromInterface($this->clock->now());
        $mark = $this->marks->findOneForPerson($station, $onDay, $person);

        if (null === $mark) {
            $this->entityManager->persist(new EditedDay($station, $onDay, $by, $at, $person, $leftOff));

            return;
        }

        $mark->touch($by, $at)->leaveOff($leftOff);
    }
}
