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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\SwapRepository;

/**
 * OFFERING A SWAP, AND WHAT HAPPENS WHEN ONE IS ACCEPTED.
 *
 * ACCEPTING IS THE ONLY THING THAT MOVES ANYBODY. Offering changes nothing:
 * both duties stand as the rotation generated them, the week grid reads as
 * though no swap existed, and the handset shows the original watches. That
 * is what makes an unaccepted offer safe to leave open over a weekend.
 *
 * AN ACCEPTED SWAP MARKS BOTH DAYS AS EDITED, and this is the line that
 * matters most: the nightly generator would otherwise regenerate those days
 * from the ring and put everybody back where the pattern says they belong,
 * quietly undoing an agreement two people made. Marking the day is how the
 * generator is told to step over it — the same mechanism a duty officer's
 * hand edit uses, because it is the same fact: somebody decided this day is
 * not what the pattern says.
 *
 * ONE OPEN OFFER PER WATCH. Two people cannot both be asked to take the same
 * cell — whichever accepted second would find the watch already gone, and
 * the roster would depend on who tapped first.
 */
final readonly class SwapService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SwapRepository $swaps,
        private EditedDayRepository $editedDays,
    ) {
    }

    /**
     * OFFER A WATCH TO SOMEBODY.
     *
     * @param Duty|null $counterDuty the watch offered in exchange, for a true swap; null for a hand-over
     *
     * @throws \LogicException when the watch already has an offer out, or the offer is to the person already on it
     */
    public function offer(Duty $duty, UserInterface $offeredTo, ?UserInterface $offeredBy = null, ?Duty $counterDuty = null): Swap
    {
        if (null !== $this->swaps->findOpenForDuty($duty)) {
            throw new \LogicException('That watch already has an offer out. Two people asked to take one cell is a roster that depends on who taps first.');
        }

        if ($duty->getPerson()->getId() === $offeredTo->getId()) {
            throw new \LogicException('That watch is already theirs; a swap with oneself moves nobody.');
        }

        $swap = new Swap($duty->getArea(), $duty, $offeredTo, $offeredBy, $counterDuty);
        $this->entityManager->persist($swap);
        $this->entityManager->flush();

        return $swap;
    }

    /**
     * ACCEPT — the one operation that moves people, and the only one the
     * person being asked can perform.
     *
     * A HAND-OVER moves one duty to the new person. A TRUE EXCHANGE moves
     * both, each to the other's person. Either way both affected DAYS are
     * marked edited so the generator leaves them alone.
     */
    public function accept(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Accepted, $at);

        $duty = $swap->getDuty();
        $counter = $swap->getCounterDuty();
        $giving = $duty->getPerson();
        $taking = $swap->getOfferedTo();

        $this->moveDutyTo($duty, $taking);
        $this->markEdited($duty, $taking, $at);

        if (null !== $counter) {
            $this->moveDutyTo($counter, $giving);
            $this->markEdited($counter, $taking, $at);
        }

        $this->entityManager->flush();

        return $swap;
    }

    /** The other person said no. Both duties stand as they were. */
    public function decline(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Declined, $at);
        $this->entityManager->flush();

        return $swap;
    }

    /** Taken back before an answer. Both duties stand as they were. */
    public function withdraw(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Withdrawn, $at);
        $this->entityManager->flush();

        return $swap;
    }

    /**
     * THE DUTY CHANGES HANDS — and it is the same row, deliberately. A swap
     * that deleted one duty and wrote another would lose the row's identity,
     * and anything that referenced it (this swap included) would be pointing
     * at nothing.
     */
    private function moveDutyTo(Duty $duty, UserInterface $person): void
    {
        $duty->reassignTo($person);
    }

    /**
     * MARK THE DAY SO THE GENERATOR LEAVES IT ALONE. Without this the
     * nightly run would rebuild the day from the ring and put both people
     * back where the pattern says they belong — quietly undoing an agreement
     * two people made, with nothing anywhere saying why.
     */
    private function markEdited(Duty $duty, UserInterface $by, \DateTimeImmutable $at): void
    {
        $existing = $this->editedDays->findOneFor($duty->getStation(), $duty->getOnDay());

        if (null !== $existing) {
            $existing->touch($by, $at);

            return;
        }

        $this->entityManager->persist(new EditedDay($duty->getStation(), $duty->getOnDay(), $by, $at));
    }
}
