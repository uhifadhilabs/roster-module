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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\StationWatchService;
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

    /**
     * NOBODY STANDS TWO WATCHES IN ONE DAY AT TWO DIFFERENT POSTS.
     *
     * Caught by opening Today, not by a test: one ranger was showing
     * "watch 1 of 2" at Alpha Gate and again at Outer Marker, adding up
     * to twenty-four hours on duty in a twenty-four hour day. The cause
     * is real and worth naming — a person may be POSTED at several posts,
     * every post's ring draws from the people posted there, and two rings
     * that share a person will both put them on the same morning. The
     * demo therefore hands each post its own people where the area has
     * enough of them to go round.
     */
    public function testNobodyIsRosteredAtTwoPostsOnTheSameDay(): void
    {
        $this->provider()->load();

        $seen = [];
        foreach ($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()) as $duty) {
            $key = $duty->getOnDay()->format('Y-m-d').'/'.$duty->getPerson()->getUuidString();
            $station = (string) $duty->getStation()->getUuidString();

            if (isset($seen[$key]) && $seen[$key] !== $station) {
                self::fail(\sprintf('%s is due at two posts on %s.', $duty->getPerson()->getFullName(), $duty->getOnDay()->format('Y-m-d')));
            }

            $seen[$key] = $station;
        }

        self::assertNotEmpty($seen);
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

    /**
     * AN AREA SOMEBODY HAS MERELY LOOKED AT IS STILL SEEDED.
     *
     * The regression for a bug that passed every test and was caught only
     * by opening the page: the "have I been here before" mark was the
     * shift vocabulary, and the module SEEDS THAT LAZILY the first time
     * any page reads it. So every area anybody had ever opened counted as
     * already done, and the demo silently skipped it — on the one area
     * the roster was actually switched on for. The mark is now a post on
     * the roster, which nothing creates by accident.
     */
    public function testAnAreaWhoseVocabularyWasAlreadyReadIsStillSeeded(): void
    {
        // What merely opening a roster page does.
        $vocabulary = $this->service(\Uhifadhi\Roster\Service\ShiftVocabularyService::class);
        self::assertInstanceOf(\Uhifadhi\Roster\Service\ShiftVocabularyService::class, $vocabulary);
        self::assertNotEmpty($vocabulary->forArea($this->area), 'Reading the vocabulary seeds it.');

        $this->provider()->load();

        self::assertNotEmpty($this->repository(StationWatchRepository::class)->findByArea($this->area));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()));
    }

    /**
     * A POST SOMEBODY HAS CONFIGURED IS LEFT EXACTLY AS IT IS — AND THE
     * REST OF THE AREA IS STILL FILLED IN.
     *
     * The second half is the correction, and it cost a render to find.
     * Idempotence was first written per AREA: anything already on the
     * roster meant "been here, skip". The one area the module was
     * actually switched on for had a SINGLE post configured by hand, so
     * every tab on it stayed at nought through run after run while the
     * seeder reported success. What a person configured is theirs; the
     * seven posts beside it were nobody's and are now seeded.
     */
    public function testAConfiguredPostIsUntouchedAndTheRestOfTheAreaIsStillSeeded(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $this->area], ['code' => 'ASC']);
        self::assertNotEmpty($posts);
        $byHand = $watches->addToRoster($posts[0]);
        $watches->save($byHand, ['radio'], 45, 180, 900);

        $this->provider()->load();

        // Untouched: still the one shift somebody chose, not the demo's.
        self::assertSame(['radio'], $byHand->getExpects());
        self::assertSame(900, $byHand->getCatchmentMetres());

        // And the area is not empty because of it.
        self::assertGreaterThan(1, \count($this->repository(StationWatchRepository::class)->findByArea($this->area)));
        self::assertNotEmpty($this->repository(RotationRepository::class)->findByArea($this->area));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()));
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

    /**
     * A POST SOMEBODY PUT ON THE ROSTER BY HAND STILL GETS A RING.
     *
     * Configuring a post and staffing it are two decisions, and only the
     * first of them had been made. The seeder read a watch on a post as
     * "been here, leave it" and returned before it had rung anybody, so a
     * post an administrator had set up sat on every tab with nothing on
     * it — and so did the whole of an area whose single post was set up
     * that way. What a person configured is still theirs: the ring
     * answers THE WATCH'S OWN demands, never the demo's list.
     */
    public function testAPostConfiguredByHandStillGetsARingAnsweringItsOwnDemands(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $this->area], ['code' => 'ASC']);
        self::assertNotEmpty($posts);
        $watches->save($watches->addToRoster($posts[0]), ['night'], 45, 180, 900);

        $this->provider()->load();

        $rotation = $this->repository(RotationRepository::class)->findOneForStation($posts[0]);
        self::assertNotNull($rotation, 'A post on the books with nobody on it is not a demo.');

        $shifts = [];
        foreach ($this->repository(DutyRepository::class)->findByStationBetween($posts[0], $this->monthStart(), $this->monthEnd()) as $duty) {
            $shifts[$duty->getShiftKey()] = true;
        }

        self::assertSame(['night'], array_keys($shifts), 'It stands the watch it was configured for, and no other.');
    }

    /**
     * AN AREA NOBODY IS POSTED AT IS STAFFED FROM THE INSTALLATION'S OWN
     * PEOPLE, THROUGH THE AREA'S OWN DOOR.
     *
     * A ring is drawn from the people posted at its post, so an area whose
     * posts carry no postings has nothing to draw and every tab on it
     * reads nought. The demo therefore posts the real people the
     * installation has — through {@see PostingService}, the area's own
     * service, so the station page, the identity band and the ring all
     * say the same thing. It only does so where the area has NO standing
     * posting at all: one unstaffed post inside a staffed area is a state
     * worth drawing, and this must not fill it in.
     */
    public function testAnAreaNobodyIsPostedAtIsStaffedFromTheInstallationsPeople(): void
    {
        $quiet = $this->anArea('quiet reserve');
        foreach (range(1, 3) as $n) {
            $this->aStation($quiet, \sprintf('quiet post %d', $n), \sprintf('QT-0%d', $n));
        }
        $this->em->flush();

        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $quiet], ['code' => 'ASC']);
        self::assertNotEmpty($postings->standingAt($posts[0]), 'Somebody is posted at the first post.');

        self::assertNotEmpty($this->repository(RotationRepository::class)->findByArea($quiet));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($quiet, $this->monthStart(), $this->monthEnd()));
    }

    /**
     * ONE UNSTAFFED POST INSIDE A STAFFED AREA IS LEFT UNSTAFFED. The area's
     * own demo leaves a post with nobody at it on purpose; filling it in
     * would delete the state.
     */
    public function testAPostNobodyIsPostedAtInsideAStaffedAreaIsLeftAlone(): void
    {
        $spare = $this->aStation($this->area, 'spare post', 'ST-09');
        $this->em->flush();

        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        self::assertSame([], $postings->standingAt($spare));
    }

    /**
     * A TRADE IN EVERY STATE IT CAN BE IN — offered, accepted, declined and
     * withdrawn. A register that only ever shows two of the four draws two
     * of its four rows in anger and the other two never.
     */
    public function testATradeIsSeededInEveryState(): void
    {
        $this->provider()->load();

        $from = RotaService::start(new \DateTimeImmutable('today'));
        $through = $from->modify(\sprintf('+%d days', RotaService::DAYS - 1));

        $states = [];
        foreach ($this->repository(SwapRepository::class)->findRecentBetween($this->area, $from, $through, 50) as $swap) {
            $states[$swap->getState()->value] = true;
        }

        foreach (SwapState::cases() as $state) {
            self::assertArrayHasKey($state->value, $states, \sprintf('No trade is %s.', $state->value));
        }
    }

    /**
     * AN ACCEPTED TRADE MARKS ITS DAY, so the next generation leaves the
     * agreement two people made exactly where they put it.
     */
    public function testAnAcceptedTradeProtectsItsDay(): void
    {
        $this->provider()->load();

        $protected = 0;
        foreach ($this->em->getRepository(Station::class)->findBy(['area' => $this->area]) as $post) {
            $protected += \count($this->repository(EditedDayRepository::class)->protectedDaysBetween($post, $this->monthStart(), $this->monthEnd()));
        }

        self::assertGreaterThan(0, $protected);
    }

    /**
     * A POST ADDED AFTER THE FIRST RUN DOES NOT DRAW PEOPLE WHO ARE
     * ALREADY IN A RING.
     *
     * The rings of earlier runs are returned from early, so the people in
     * them were never counted as spoken for and the next post to be rung
     * drew them again — two watches on one morning, at two posts, on a
     * park that had merely been seeded twice. Every ring already standing
     * in the area is read before the walk starts.
     */
    public function testAPostRungOnALaterRunDoesNotDrawPeopleAlreadyInARing(): void
    {
        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $later = $this->aStation($this->area, 'late post', 'ST-09');
        $this->em->flush();

        foreach ($this->repository(RotationRepository::class)->findByArea($this->area)[0]->getPool() as $member) {
            $postings->post($later, $member->getPerson(), PostingSource::WrittenHere);
        }

        $this->provider()->load();

        $seen = [];
        foreach ($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()) as $duty) {
            $key = $duty->getOnDay()->format('Y-m-d').'/'.$duty->getPerson()->getUuidString();
            $station = (string) $duty->getStation()->getUuidString();

            if (isset($seen[$key]) && $seen[$key] !== $station) {
                self::fail(\sprintf('%s is due at two posts on %s.', $duty->getPerson()->getFullName(), $duty->getOnDay()->format('Y-m-d')));
            }

            $seen[$key] = $station;
        }

        self::assertNotEmpty($seen);
    }
}
