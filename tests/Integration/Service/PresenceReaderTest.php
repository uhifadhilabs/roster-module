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
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PersonWatch;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Contracts\Area\UnverifiedReason;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\Fixtures\StubPresence;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE JOIN NEITHER SIDE CAN MAKE ALONE — and, since the owner's ruling, the
 * fact that A DAY HOLDS ANY NUMBER OF WATCHES.
 *
 * The area is stubbed here on purpose. What is under test is this module's
 * reading of whatever the contract returns, and a stub is the only way to
 * hand it a two-watch day without inventing check-in rows the area owns.
 */
final class PresenceReaderTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada');
        $this->em->flush();

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate)->expect(['day']);

        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', new \DateTimeImmutable('2026-09-19')));
        $this->em->flush();
    }

    /**
     * @param list<PersonDay> $reported
     */
    private function readerSeeing(array $reported): PresenceReader
    {
        $presence = self::getContainer()->get('test_public.'.PresenceProviderInterface::class);
        self::assertInstanceOf(PresenceProviderInterface::class, $presence);

        return new PresenceReader(
            new StubPresence($reported),
            $this->repository(\Uhifadhi\Roster\Repository\DutyRepository::class),
            $this->repository(\Uhifadhi\Roster\Repository\ShiftRepository::class),
            $this->repository(\Uhifadhi\Roster\Repository\StationWatchRepository::class),
            $this->repository(\Uhifadhi\Roster\Repository\RotationRepository::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function repository(string $class): object
    {
        $repository = $this->service($class);
        self::assertInstanceOf($class, $repository);

        return $repository;
    }

    /** ONE STRETCH OF DUTY — a check-in, what it claimed, and what the positions said. */
    private function aWatch(string $at, DayState $state, ?UnverifiedReason $why = null, ?string $lastPing = null, ?string $until = null): PersonWatch
    {
        return new PersonWatch(
            clientRef: 'w-'.$at,
            state: $state,
            stationUuid: (string) $this->gate->getUuidString(),
            stationName: 'north gate post',
            unverifiedReason: $why,
            occurredAt: new \DateTimeImmutable($at),
            endedAt: null === $until ? null : new \DateTimeImmutable($until),
            lastPingAt: null === $lastPing ? null : new \DateTimeImmutable($lastPing),
            pings: null === $lastPing ? 0 : 3,
        );
    }

    /**
     * ADA'S WHOLE DAY, built the way the AREA builds it: the day's state
     * is the LAST watch's reading, and its totals are the watches' summed.
     * The stub must impersonate the contract faithfully or the tests pass
     * against a shape no installation produces.
     */
    private function aDay(PersonWatch $first, PersonWatch ...$rest): PersonDay
    {
        $watches = [$first, ...$rest];
        $last = $watches[\count($watches) - 1];
        $pings = 0;
        $lastPing = null;
        foreach ($watches as $watch) {
            $pings += $watch->pings;
            if (null !== $watch->lastPingAt && (null === $lastPing || $watch->lastPingAt > $lastPing)) {
                $lastPing = $watch->lastPingAt;
            }
        }

        return new PersonDay(
            personUuid: (string) $this->ada->getUuidString(),
            personName: 'Ada Example',
            localDate: '2026-09-19',
            state: $last->state,
            watches: array_values($watches),
            occurredAt: $first->occurredAt,
            lastPingAt: $lastPing,
            pings: $pings,
        );
    }

    /**
     * THE RULING: a day holds any number of watches. Two check-ins for one
     * person on one day are TWO WATCHES, and both survive the read.
     *
     * This is the case the old reader silently lost: it keyed the area's
     * answer by person uuid, so the morning at the gate vanished the moment
     * an afternoon watch arrived.
     */
    public function testADayHoldsAnyNumberOfWatchesAndKeepsAllOfThem(): void
    {
        $reader = $this->readerSeeing([$this->aDay(
            $this->aWatch('2026-09-19 06:02', DayState::AtPostVerified, lastPing: '2026-09-19 11:40', until: '2026-09-19 12:00'),
            $this->aWatch('2026-09-19 14:10', DayState::WorkingElsewhere, lastPing: '2026-09-19 16:20', until: '2026-09-19 16:40'),
        )]);

        $posts = $reader->postsOn($this->area, new \DateTimeImmutable('2026-09-19'), new \DateTimeImmutable('2026-09-19 17:00'));

        self::assertCount(1, $posts);
        $person = $posts[0]->rostered[0];

        self::assertSame(2, $person->watchCount(), 'Both check-ins are the day; neither replaces the other.');
        self::assertSame(DayState::AtPostVerified, $person->watches()[0]->state);
        self::assertSame(DayState::WorkingElsewhere, $person->watches()[1]->state);
        // AND THE DAY ADDS UP: 358 minutes at the gate and 150 on the
        // escort are one person's 508, which no single watch can state
        // and which the row showing only the latest would report as 150.
        self::assertSame(508, $person->minutesOnDuty());
        self::assertFalse($person->isStillOut(), 'Both watches closed.');
    }

    /**
     * THE SUMMARY READS THE DAY'S STATE, WHICH IS THE LAST WATCH'S.
     *
     * The contract states it: what somebody is doing now — or finished the
     * day doing — is what a board asks when it colours a name. So the
     * module does NOT re-fold the list into a second verdict; a row that
     * chose the first watch while the band beside it read the area's day
     * would give two answers to one question.
     */
    public function testTheSummaryReadsTheDaysStateAndDoesNotReFoldTheWatches(): void
    {
        $reader = $this->readerSeeing([$this->aDay(
            $this->aWatch('2026-09-19 06:02', DayState::AtPostVerified),
            $this->aWatch('2026-09-19 14:10', DayState::NotWorking),
        )]);

        $person = $reader->postsOn($this->area, new \DateTimeImmutable('2026-09-19'))[0]->rostered[0];

        self::assertSame(DayState::NotWorking, $person->state());
        // And the morning has NOT vanished: it is a row of its own.
        self::assertSame(2, $person->watchCount());
        self::assertSame(DayState::AtPostVerified, $person->watches()[0]->state);
    }

    /**
     * PRESENT IF ANY WATCH COUNTS. Somebody who spent the morning on an
     * escort and the afternoon at the post was present, and a fold that
     * looked only at the last row would say otherwise on a day that ended
     * with a checkout.
     */
    public function testAPersonIsPresentIfAnyOfTheDaysWatchesCounts(): void
    {
        $reader = $this->readerSeeing([$this->aDay(
            $this->aWatch('2026-09-19 06:02', DayState::NotWorking),
            $this->aWatch('2026-09-19 14:10', DayState::AtPostVerified),
        )]);

        self::assertTrue($reader->postsOn($this->area, new \DateTimeImmutable('2026-09-19'))[0]->rostered[0]->isPresent());
    }

    /**
     * FLAGGED IF ANY WATCH IS. One bad claim in three is still a claim
     * somebody has to look at, and averaging it away is exactly what the
     * flag exists to prevent.
     */
    public function testAPersonIsFlaggedIfAnyOfTheDaysWatchesIs(): void
    {
        $reader = $this->readerSeeing([$this->aDay(
            $this->aWatch('2026-09-19 06:02', DayState::AtPostVerified),
            $this->aWatch('2026-09-19 14:10', DayState::AtPostUnverified, UnverifiedReason::OutsideRing),
        )]);

        $person = $reader->postsOn($this->area, new \DateTimeImmutable('2026-09-19'))[0]->rostered[0];

        self::assertTrue($person->isFlagged());
        self::assertSame(UnverifiedReason::OutsideRing, $person->watches()[1]->unverifiedReason);
    }

    /**
     * THE POST'S SILENCE IS MEASURED FROM THE NEWEST THING ANYBODY SENT,
     * across every watch — not from the last row of each person. A post
     * whose morning reported and whose afternoon has not is not silent
     * since this morning.
     */
    public function testThePostsSilenceIsMeasuredFromTheNewestWatch(): void
    {
        $reader = $this->readerSeeing([$this->aDay(
            $this->aWatch('2026-09-19 06:02', DayState::AtPostVerified, lastPing: '2026-09-19 06:30'),
            $this->aWatch('2026-09-19 14:10', DayState::AtPostVerified, lastPing: '2026-09-19 16:45'),
        )]);

        $post = $reader->postsOn($this->area, new \DateTimeImmutable('2026-09-19'), new \DateTimeImmutable('2026-09-19 17:00'))[0];

        // Fifteen minutes since 16:45, not eleven hours since 06:30.
        self::assertSame(15, $post->silentFor);
        self::assertSame(PostState::Reporting, $post->state);
    }

    /** Nothing reported at all is a no-check-in, and not an absence. */
    public function testAPersonWithNothingReportedIsANoCheckInAndNotAnAbsence(): void
    {
        $person = $this->readerSeeing([])->postsOn($this->area, new \DateTimeImmutable('2026-09-19'))[0]->rostered[0];

        self::assertSame(0, $person->watchCount());
        self::assertSame(DayState::NoCheckIn, $person->state());
        self::assertFalse($person->isPresent());
    }
}
