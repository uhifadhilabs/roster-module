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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * A SWAP IS TWO CELLS, SENT TO THE HANDSET FOR ACCEPTANCE — and the test
 * that matters most is the last one: an accepted swap survives the nightly
 * generator.
 */
final class SwapServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ada;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada');
        $this->ben = $this->aPerson('ben@example.test', 'Ben');
        $this->em->flush();
    }

    private function swaps(): SwapService
    {
        $service = $this->service(SwapService::class);
        self::assertInstanceOf(SwapService::class, $service);

        return $service;
    }

    private function aDuty(User $person, string $shiftKey, string $day): Duty
    {
        $duty = new Duty($this->area, $this->gate, $person, $shiftKey, new \DateTimeImmutable($day));
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    /** OFFERING MOVES NOBODY — the whole reason a swap is not just an edit. */
    public function testOfferingChangesNothingUntilItIsAccepted(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');

        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        self::assertSame(SwapState::Offered, $swap->getState());
        self::assertTrue($swap->getState()->isOpen());
        self::assertSame($this->ada->getId(), $duty->getPerson()->getId(), 'The watch is still Ada\'s until Ben accepts.');
        self::assertNull($swap->getRespondedAt());
    }

    /** A hand-over moves one watch to the person who accepted it. */
    public function testAcceptingAHandOverMovesTheWatch(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-09-18 20:00'));

        self::assertSame(SwapState::Accepted, $swap->getState());
        self::assertSame($this->ben->getId(), $duty->getPerson()->getId());
        self::assertSame('2026-09-18 20:00', $swap->getRespondedAt()?->format('Y-m-d H:i'));
    }

    /** A true exchange moves both watches, each to the other's person. */
    public function testAcceptingAnExchangeMovesBothWatches(): void
    {
        $hers = $this->aDuty($this->ada, 'day', '2026-09-19');
        $his = $this->aDuty($this->ben, 'night', '2026-09-21');

        $swap = $this->swaps()->offer($hers, $this->ben, $this->ada, $his);
        self::assertTrue($swap->isExchange());

        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-09-18 20:00'));

        self::assertSame($this->ben->getId(), $hers->getPerson()->getId());
        self::assertSame($this->ada->getId(), $his->getPerson()->getId());
    }

    /** Declining and withdrawing leave both watches exactly as they were. */
    public function testDecliningLeavesTheWatchWhereItWas(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->swaps()->decline($swap, new \DateTimeImmutable('2026-09-18 20:00'));

        self::assertSame(SwapState::Declined, $swap->getState());
        self::assertSame($this->ada->getId(), $duty->getPerson()->getId());
    }

    public function testWithdrawingLeavesTheWatchWhereItWas(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->swaps()->withdraw($swap, new \DateTimeImmutable('2026-09-18 20:00'));

        self::assertSame(SwapState::Withdrawn, $swap->getState());
        self::assertSame($this->ada->getId(), $duty->getPerson()->getId());
    }

    /** An answered offer is not answered again; doing it twice would do it twice. */
    public function testAnAnsweredOfferCannotBeAnsweredAgain(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-09-18 20:00'));

        $this->expectException(\LogicException::class);

        $this->swaps()->decline($swap, new \DateTimeImmutable('2026-09-18 21:00'));
    }

    /**
     * ONE OPEN OFFER PER WATCH. Two people asked to take one cell is a
     * roster that depends on who taps first.
     */
    public function testAWatchCannotHaveTwoOffersOutAtOnce(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $cleo = $this->aPerson('cleo@example.test', 'Cleo');
        $this->em->flush();
        $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->expectException(\LogicException::class);

        $this->swaps()->offer($duty, $cleo, $this->ada);
    }

    /**
     * ASKING SOMEBODY ELSE AFTER A NO IS THE ORDINARY WAY A HOLE GETS
     * FILLED — which is why the one-open-offer rule is about OPEN offers and
     * not about the watch having ever been offered.
     */
    public function testAWatchCanBeOfferedAgainAfterARefusal(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');
        $cleo = $this->aPerson('cleo@example.test', 'Cleo');
        $this->em->flush();

        $first = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->decline($first, new \DateTimeImmutable('2026-09-18 20:00'));

        $second = $this->swaps()->offer($duty, $cleo, $this->ada);

        self::assertSame(SwapState::Offered, $second->getState());
    }

    public function testAWatchIsNotOfferedToThePersonAlreadyOnIt(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-09-19');

        $this->expectException(\LogicException::class);

        $this->swaps()->offer($duty, $this->ada, $this->ada);
    }

    /**
     * THE ONE THAT MATTERS: AN ACCEPTED SWAP SURVIVES THE NIGHTLY RUN.
     *
     * Without the day being marked edited, the generator rebuilds it from
     * the ring and puts both people back where the pattern says they belong
     * — quietly undoing an agreement two people made, with nothing anywhere
     * saying why.
     */
    public function testAnAcceptedSwapIsNotUndoneByTheNextGeneratorRun(): void
    {
        $rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', Cycle::OFF]),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 1],
            42,
        )->standAt($this->gate);
        $this->em->persist($rotation);
        $this->em->persist(new RotationPoolMember($rotation, $this->ada, 0));
        $this->em->flush();

        $generator = $this->service(RotationGenerator::class);
        self::assertInstanceOf(RotationGenerator::class, $generator);
        $generator->generate($rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        $duties = $this->service(DutyRepository::class);
        self::assertInstanceOf(DutyRepository::class, $duties);

        $onTheDay = $duties->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-16'), new \DateTimeImmutable('2026-09-16'));
        self::assertCount(1, $onTheDay, 'The ring stands Ada on the 16th.');
        $duty = $onTheDay[0];

        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-09-15 20:00'));
        self::assertSame($this->ben->getId(), $duty->getPerson()->getId());

        // The nightly run, over the same window.
        $run = $generator->generate($rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertContains('2026-09-16', $run->protectedDays, 'The swapped day has to be protected, or the ring takes it back.');

        $this->em->clear();
        $after = $this->service(DutyRepository::class);
        self::assertInstanceOf(DutyRepository::class, $after);
        $stillTheirs = $after->findByStationBetween(
            $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']) ?? throw new \LogicException('The fixture station vanished.'),
            new \DateTimeImmutable('2026-09-16'),
            new \DateTimeImmutable('2026-09-16'),
        );

        self::assertCount(1, $stillTheirs);
        self::assertSame('ben@example.test', $stillTheirs[0]->getPerson()->getEmail(), 'Ben agreed to take the watch; the generator must not hand it back to Ada.');
    }
}
