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

namespace Uhifadhi\Roster\Tests\Integration\Devkit;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Roster\Devkit\PresenceContentProvider;
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE PROOF BEHIND THE PLAN — the check-ins and the positions that make
 * the demo's rostered days read like days somebody actually worked.
 *
 * IT WRITES THROUGH THE AREA'S OWN HANDSET API and derives nothing. The
 * roster is forbidden to compute presence, and a seeder that wrote a
 * `DayState` into a column would be doing exactly that — and would go on
 * agreeing with itself long after the real derivation had changed under
 * it. So this provider CLAIMS and PINGS the way a phone does, and every
 * reading in these assertions comes back out of the area's own service.
 *
 * WHAT IT HAS TO PRODUCE is the set of states the screens draw. A demo
 * where everybody is quietly at post exercises one branch of Today, one
 * colour on the board and none of the flags, and would have shipped every
 * one of those broken.
 */
final class PresenceContentProviderTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $people = [];
        foreach (range(1, 6) as $n) {
            $people[] = $this->aPerson(\sprintf('ranger%d@example.test', $n), 'Ranger'.$n);
        }

        foreach (range(1, 4) as $n) {
            $station = $this->aStation($this->area, \sprintf('post %d', $n), \sprintf('ST-0%d', $n));
            $this->em->flush();
            foreach ([0, 1, 2] as $offset) {
                $postings->post($station, $people[($n - 1 + $offset) % 6], PostingSource::WrittenHere);
            }
        }

        $this->em->flush();

        $plan = $this->service(RosterContentProvider::class);
        self::assertInstanceOf(RosterContentProvider::class, $plan);
        $plan->load();
    }

    private function provider(): PresenceContentProvider
    {
        $provider = $this->service(PresenceContentProvider::class);
        self::assertInstanceOf(PresenceContentProvider::class, $provider);

        return $provider;
    }

    private function presence(): PresenceProviderInterface
    {
        $presence = self::getContainer()->get('test_public.'.PresenceProviderInterface::class);
        self::assertInstanceOf(PresenceProviderInterface::class, $presence);

        return $presence;
    }

    /**
     * EVERY DAY OF THE MONTH UP TO TODAY, as the area reads it back.
     *
     * @return list<\Uhifadhi\Contracts\Area\PersonDay>
     */
    private function theMonthAsRead(): array
    {
        $days = [];
        $day = new \DateTimeImmutable('first day of this month')->setTime(0, 0);
        $today = new \DateTimeImmutable('today');

        while ($day <= $today) {
            foreach ($this->presence()->dayIn((string) $this->area->getUuidString(), $day->format('Y-m-d')) as $personDay) {
                $days[] = $personDay;
            }
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    public function testItIsSeededAfterTheRosterItProves(): void
    {
        $provider = $this->provider();

        self::assertSame('roster-presence', $provider->key());
        self::assertSame(['roster'], $provider->dependsOn());
    }

    /** THE DAYS ARE THERE AT ALL, and they are the area's reading, not ours. */
    public function testTheMonthSoFarHasBeenWorked(): void
    {
        $this->provider()->load();

        self::assertNotEmpty($this->theMonthAsRead(), 'A month of watches with nobody reporting is an empty demo.');
    }

    /**
     * THE STATES THE SCREENS DRAW ALL APPEAR. Named one by one rather than
     * counted, because the point is that no branch of Today, the board or
     * the live plate goes unexercised.
     */
    public function testEveryReadingTheScreensDrawAppearsAtLeastOnce(): void
    {
        $this->provider()->load();

        $seen = [];
        foreach ($this->theMonthAsRead() as $day) {
            foreach ($day->watches as $watch) {
                $seen[$watch->state->value] = true;
            }
        }

        self::assertArrayHasKey(DayState::WorkingElsewhere->value, $seen, 'Somebody out on an escort.');
        self::assertArrayHasKey(DayState::NotWorking->value, $seen, 'Somebody unfit for duty.');
        self::assertArrayHasKey(DayState::Special->value, $seen, 'Somebody on a special assignment.');
        self::assertArrayHasKey(DayState::AtPostUnverified->value, $seen, 'A claim the positions do not bear out.');
    }

    /** A CLAIM NOBODY CLOSED — a fact the board has to be able to draw. */
    public function testSomebodyWentHomeWithoutCheckingOut(): void
    {
        $this->provider()->load();

        $found = false;
        foreach ($this->theMonthAsRead() as $day) {
            foreach ($day->watches as $watch) {
                $found = $found || $watch->notCheckedOut;
            }
        }

        self::assertTrue($found);
    }

    /**
     * A DAY WITH TWO WATCHES ON IT — the ruling's own case, and the one a
     * demo of single intervals would never produce. A morning at the gate
     * and an afternoon on an escort are one person's day.
     */
    public function testSomebodysDayHoldsTwoWatches(): void
    {
        $this->provider()->load();

        $longest = 0;
        foreach ($this->theMonthAsRead() as $day) {
            $longest = max($longest, \count($day->watches));
        }

        self::assertGreaterThan(1, $longest);
    }

    /** POSITIONS BEHIND THE CLAIMS, so the live plate has something to draw. */
    public function testTheClaimsCarryPositions(): void
    {
        $this->provider()->load();

        $pings = 0;
        foreach ($this->theMonthAsRead() as $day) {
            $pings += $day->pings;
        }

        self::assertGreaterThan(0, $pings);
    }

    /**
     * NOTHING IS REPORTED FROM THE FUTURE. A check-in dated next week is
     * not demo content, it is a bug that would make the board read a plan
     * as a fact.
     */
    public function testNothingIsReportedFromTheFuture(): void
    {
        $this->provider()->load();

        $tomorrow = new \DateTimeImmutable('tomorrow');
        $end = new \DateTimeImmutable('last day of this month')->setTime(0, 0);

        for ($day = $tomorrow; $day <= $end; $day = $day->modify('+1 day')) {
            self::assertSame([], $this->presence()->dayIn((string) $this->area->getUuidString(), $day->format('Y-m-d')), 'A watch reported before it was stood.');
        }
    }

    /** A SECOND RUN CHANGES NOTHING: the claims carry their own references. */
    public function testASecondRunChangesNothing(): void
    {
        $this->provider()->load();
        $before = array_map(static fn (object $d): string => $d->localDate.'/'.$d->personUuid.'/'.\count($d->watches), $this->theMonthAsRead());

        $this->provider()->load();
        $after = array_map(static fn (object $d): string => $d->localDate.'/'.$d->personUuid.'/'.\count($d->watches), $this->theMonthAsRead());

        self::assertSame($before, $after);
    }
}
