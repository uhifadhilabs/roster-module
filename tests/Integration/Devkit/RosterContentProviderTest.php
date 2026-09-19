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
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE DEMO PLAN — what `fixtures:demo` puts on the roster so that every tab
 * reads like a working park rather than a set of empty frames.
 *
 * IT IS BUILT ON THE AREA'S OWN CONTENT, not on a parallel world of its
 * own: the posts are the ones the area seeded, and the people ringed at
 * each are the people POSTED there. A demo that invented its own stations
 * would be a demo of a product that does not exist, and the first thing it
 * would hide is the seam it is supposed to exercise.
 *
 * EVERYTHING FALLS IN THE CURRENT MONTH, because that is the window every
 * tab opens on. Content seeded into a fixed calendar month reads rich on
 * the day it was written and empty forever after — the one bug a demo
 * cannot afford, since nobody looks at a demo twice.
 */
final class RosterContentProviderTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();

        // Four posts and six people, posted around them the way the area's
        // own seeder does it: the demo hangs on whatever it finds.
        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $people = [];
        foreach (range(1, 6) as $n) {
            $people[] = $this->aPerson(\sprintf('ranger%d@example.test', $n), 'Ranger'.$n);
        }

        foreach (range(1, 4) as $n) {
            $station = $this->aStation($this->area, \sprintf('post %d', $n), \sprintf('ST-0%d', $n));
            $this->em->flush();

            // Three each, walked round the roster, so the posts share people.
            foreach ([0, 1, 2] as $offset) {
                $postings->post($station, $people[($n - 1 + $offset) % 6], PostingSource::WrittenHere);
            }
        }

        $this->em->flush();
    }

    private function provider(): RosterContentProvider
    {
        $provider = $this->service(RosterContentProvider::class);
        self::assertInstanceOf(RosterContentProvider::class, $provider);

        return $provider;
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

    private function monthStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month')->setTime(0, 0);
    }

    private function monthEnd(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('last day of this month')->setTime(0, 0);
    }

    /** THE ORDER IT IS SEEDED IN is stated as what it is built on. */
    public function testItIsSeededAfterTheContentItHangsOn(): void
    {
        $provider = $this->provider();

        self::assertSame('roster', $provider->key());
        self::assertSame(['area', 'team', 'zone', 'station'], $provider->dependsOn());
        self::assertNotSame('', trim($provider->label()));
        self::assertNotSame('', trim($provider->description()));
    }

    /** THE VOCABULARY FIRST: nothing can be rostered onto a shift that has no name. */
    public function testItSeedsTheShiftVocabulary(): void
    {
        $this->provider()->load();

        $keys = array_map(
            static fn (object $shift): string => $shift->getKey(),
            $this->repository(ShiftRepository::class)->findByArea($this->area),
        );

        self::assertContains('day', $keys);
        self::assertContains('night', $keys);
    }

    /**
     * THE POSTS ARE THE AREA'S, and they come onto the roster with what each
     * asks for — including one that asks for NOTHING, because a post on the
     * books with no watch is a state the board has to draw and an all-alike
     * demo never produces it.
     */
    public function testItPutsTheAreasOwnPostsOnTheRosterWithDifferentDemands(): void
    {
        $this->provider()->load();

        $watches = $this->repository(StationWatchRepository::class)->findByArea($this->area);
        self::assertNotEmpty($watches);

        $demands = [];
        foreach ($watches as $watch) {
            $demands[] = $watch->getExpects();
            self::assertSame($this->area->getId(), $watch->getStation()->getArea()?->getId());
        }

        self::assertContains([], $demands, 'A post that asks for no watch.');
        self::assertContains(['day', 'night'], $demands, 'A post that is manned round the clock.');
        self::assertGreaterThan(1, \count(array_unique(array_map('serialize', $demands))), 'Not every post asks the same thing.');
    }

    /**
     * THE RING AT A POST IS THE PEOPLE POSTED THERE. Reading the pool from
     * the area's postings is the whole point of the seam: a demo that picked
     * people at random would show a roster nobody at that post appears on.
     */
    public function testEveryRingDrawsFromThePeoplePostedAtThatPost(): void
    {
        $this->provider()->load();

        $rotations = $this->repository(RotationRepository::class)->findByArea($this->area);
        self::assertNotEmpty($rotations);

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        foreach ($rotations as $rotation) {
            $station = $rotation->watchStation();
            self::assertNotNull($station, 'A per-post ring names its post.');

            $posted = [];
            foreach ($postings->standingAt($station) as $posting) {
                $posted[] = $posting->getPerson()?->getUuidString();
            }

            self::assertNotEmpty($rotation->getPool(), 'A ring with nobody in it generates nothing.');
            foreach ($rotation->getPool() as $member) {
                self::assertContains($member->getPerson()->getUuidString(), $posted, 'Somebody in the ring who is not posted here.');
            }
        }
    }

    /**
     * THE WHOLE OF THE CURRENT MONTH IS ROSTERED, both ends of it — a demo
     * that generated from today forward leaves the first half of every
     * calendar and every fortnight grid empty.
     */
    public function testItRostersTheWholeOfTheCurrentMonth(): void
    {
        $this->provider()->load();

        $duties = $this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd());
        self::assertNotEmpty($duties);

        $days = [];
        foreach ($duties as $duty) {
            $days[$duty->getOnDay()->format('Y-m-d')] = true;
        }

        self::assertArrayHasKey($this->monthStart()->format('Y-m-d'), $days, 'The first of the month is rostered.');
        self::assertArrayHasKey($this->monthEnd()->format('Y-m-d'), $days, 'So is the last.');
        self::assertGreaterThan(20, \count($days), 'Nearly every day of the month carries a watch.');
    }

    /** NOTHING IS ROSTERED OUTSIDE THE MONTH the tabs are looking at. */
    public function testItRostersNothingOutsideTheCurrentMonth(): void
    {
        $this->provider()->load();

        $before = $this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart()->modify('-2 months'), $this->monthStart()->modify('-1 day'));
        $after = $this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthEnd()->modify('+1 day'), $this->monthEnd()->modify('+2 months'));

        self::assertSame([], $before);
        self::assertSame([], $after);
    }

    /** ABSENCES AND SWAPS, inside the window, so both cards read. */
    public function testItSeedsAbsencesAndSwapsInsideTheWindow(): void
    {
        $this->provider()->load();

        $absences = $this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd());
        self::assertNotEmpty($absences, 'Somebody is away this month.');

        self::assertNotEmpty(
            $this->repository(SwapRepository::class)->findOpenBetween($this->area, $this->monthStart(), $this->monthEnd()),
            'A trade is in flight — the pair the grid marks on both cells.',
        );

        $states = array_map(
            static fn (object $swap): string => $swap->getState()->value,
            $this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->monthStart(), $this->monthEnd(), 50),
        );
        self::assertGreaterThan(1, \count(array_unique($states)), 'And one already answered, so the card is not all one state.');
    }

    /**
     * A SECOND RUN CHANGES NOTHING. `fixtures:demo` is run again and again
     * on a working database, and a seeder that doubled its content every
     * time would make the second run a different product from the first.
     */
    public function testASecondRunChangesNothing(): void
    {
        $this->provider()->load();

        $counted = static fn (array $rows): int => \count($rows);
        $before = [
            $counted($this->repository(ShiftRepository::class)->findByArea($this->area)),
            $counted($this->repository(StationWatchRepository::class)->findByArea($this->area)),
            $counted($this->repository(RotationRepository::class)->findByArea($this->area)),
            $counted($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->monthStart(), $this->monthEnd(), 50)),
        ];

        $this->provider()->load();

        $after = [
            $counted($this->repository(ShiftRepository::class)->findByArea($this->area)),
            $counted($this->repository(StationWatchRepository::class)->findByArea($this->area)),
            $counted($this->repository(RotationRepository::class)->findByArea($this->area)),
            $counted($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->monthStart(), $this->monthEnd(), 50)),
        ];

        self::assertSame($before, $after);
    }

    /** AN AREA WITH NO POSTS IS LEFT ALONE, rather than seeded into nothing. */
    public function testAnAreaWithNoPostsIsLeftAlone(): void
    {
        $bare = $this->anArea('bare reserve');
        $this->em->flush();

        $this->provider()->load();

        self::assertSame([], $this->repository(StationWatchRepository::class)->findByArea($bare));
        self::assertSame([], $this->repository(RotationRepository::class)->findByArea($bare));
    }
}
