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

namespace Uhifadhi\Roster\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Roster\Watch;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Enum\DutyState;
use Uhifadhi\Roster\Module\RosterWatches;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE ONE QUESTION THE AREA ASKS THE ROSTER, answered against a real
 * database — because what the handset draws for a month depends on it.
 */
final class RosterWatchesTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ranger = $this->aPerson('ada@example.test', 'Ada');
        $this->em->flush();
    }

    private function watches(): RosterWatches
    {
        $provider = $this->service(RosterWatches::class);
        self::assertInstanceOf(RosterWatches::class, $provider);

        return $provider;
    }

    private function aDuty(string $shiftKey, string $day, DutyState $state = DutyState::Published): Duty
    {
        $duty = new Duty($this->area, $this->gate, $this->ranger, $shiftKey, new \DateTimeImmutable($day));
        $duty->setState($state);
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    /**
     * @return list<Watch>
     */
    private function month(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->watches()->watchesFor(
            (string) $this->area->getUuidString(),
            (string) $this->ranger->getUuidString(),
            $from,
            $to,
        );
    }

    public function testItIsTheContractTheAreaAsksThrough(): void
    {
        self::assertInstanceOf(WatchProviderInterface::class, $this->watches());
    }

    public function testADayWatchBecomesAWatchWithItsOwnWindow(): void
    {
        $this->aDuty('day', '2026-09-19');

        $watches = $this->month();

        self::assertCount(1, $watches);
        self::assertSame('2026-09-19', $watches[0]->localDate);
        self::assertSame('2026-09-19 06:00', $watches[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-19 18:00', $watches[0]->endsAt->format('Y-m-d H:i'));
        self::assertSame('day', $watches[0]->label);
        self::assertSame($this->gate->getUuidString(), $watches[0]->stationUuid);
    }

    /**
     * A NIGHT WATCH ENDS ON THE FOLLOWING MORNING, and its `localDate` is
     * still the day it BEGAN on. The contract states the day for exactly
     * this case, and a phone that derived the day from `startsAt`'s offset
     * would file half the park's nights on the wrong date.
     */
    public function testANightWatchEndsTheNextMorningAndKeepsTheDayItBeganOn(): void
    {
        $this->aDuty('night', '2026-09-19');

        $watches = $this->month();

        self::assertCount(1, $watches);
        self::assertSame('2026-09-19', $watches[0]->localDate);
        self::assertSame('2026-09-19 18:00', $watches[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-20 06:00', $watches[0]->endsAt->format('Y-m-d H:i'));
    }

    /**
     * A DAY WITH NO WATCH IS A REST DAY, AND IT IS ANSWERED BY SAYING
     * NOTHING. There is no rest value in the contract and there should not
     * be: the phone draws no row, no dot and no reminder for a day nothing
     * came back for.
     */
    public function testARestDayIsAnsweredBySayingNothing(): void
    {
        $this->aDuty('day', '2026-09-19');

        $days = array_map(static fn (Watch $watch): string => $watch->localDate, $this->month());

        self::assertSame(['2026-09-19'], $days);
        self::assertNotContains('2026-09-20', $days, 'A day the ring stood them down must produce no row at all.');
    }

    /**
     * A CANCELLED WATCH IS NOT SENT. The row stays in the database — a watch
     * that was called off is a fact somebody may have to read back — and the
     * phone must not remind anybody to stand it.
     */
    public function testACancelledWatchIsNotSentToTheHandset(): void
    {
        $this->aDuty('day', '2026-09-19', DutyState::Cancelled);
        $this->aDuty('night', '2026-09-20');

        $days = array_map(static fn (Watch $watch): string => $watch->localDate, $this->month());

        self::assertSame(['2026-09-20'], $days);
    }

    /** A planned watch is still a watch the phone shows; only cancelled is withheld. */
    public function testAPlannedWatchIsStillAWatch(): void
    {
        $this->aDuty('day', '2026-09-19', DutyState::Planned);

        self::assertCount(1, $this->month());
    }

    public function testItAnswersOnlyTheRangerAsked(): void
    {
        $other = $this->aPerson('ben@example.test', 'Ben');
        $this->em->persist(new Duty($this->area, $this->gate, $other, 'day', new \DateTimeImmutable('2026-09-19')));
        $this->aDuty('night', '2026-09-19');

        $watches = $this->month();

        self::assertCount(1, $watches);
        self::assertSame('night', $watches[0]->label);
    }

    public function testItAnswersOnlyTheWindowAsked(): void
    {
        $this->aDuty('day', '2026-09-19');
        $this->aDuty('day', '2026-10-19');

        self::assertCount(1, $this->month('2026-09-01', '2026-09-30'));
    }

    /**
     * A PHONE THAT SENDS RUBBISH GETS AN EMPTY MONTH, not a 500. The
     * contract takes `2026-09-01`; anything else is a caller's mistake and
     * answering it with an exception would take somebody's handset down.
     */
    public function testAMalformedRequestAnswersAnEmptyMonthRatherThanFailing(): void
    {
        $this->aDuty('day', '2026-09-19');

        self::assertSame([], $this->month('not-a-date', '2026-09-30'));
        self::assertSame([], $this->month('2026-09-30', '2026-09-01'), 'A range that ends before it starts is empty, not reversed.');
        self::assertSame([], $this->watches()->watchesFor('not-a-uuid', (string) $this->ranger->getUuidString(), '2026-09-01', '2026-09-30'));
    }

    /** An area this installation does not have is an empty month, never an error. */
    public function testAnUnknownAreaAnswersAnEmptyMonth(): void
    {
        self::assertSame([], $this->watches()->watchesFor(
            '0199a1f0-0000-7000-8000-000000000000',
            (string) $this->ranger->getUuidString(),
            '2026-09-01',
            '2026-09-30',
        ));
    }
}
