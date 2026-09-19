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

namespace Uhifadhi\Roster\Devkit;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;

/**
 * THE DEMO ROSTER — the plan: what each post asks for, who is in its ring,
 * who that puts on watch every day of THIS month, who is away, and which
 * two people are trying to trade.
 *
 * IT HANGS ON THE AREA'S OWN CONTENT AND INVENTS NONE OF IT. The posts are
 * the ones the area seeded and the ring at each is THE PEOPLE POSTED
 * THERE, read through the area's postings. That is not tidiness: a demo
 * that picked its own people would put somebody on the night watch at a
 * post whose own page never lists them, and the first thing it would hide
 * is the seam between the two modules — which is the thing most worth
 * demonstrating.
 *
 * IT IS ALWAYS THIS MONTH. Every tab opens on the month it is opened in,
 * so content seeded into a fixed calendar month reads rich on the day it
 * was written and empty forever after. There are no literal dates here,
 * only offsets from today.
 *
 * IT SEEDS THE STATES THE SCREENS HAVE TO DRAW, on purpose — a post that
 * asks for nothing, a post manned round the clock, a ring too small for
 * what its post asks, somebody away, a trade in flight and a trade already
 * answered. A demo where every post is alike exercises one branch of every
 * page and ships the rest broken.
 *
 * IT SEEDS ONCE, PER AREA. An area that already has a shift vocabulary has
 * been here before and is left exactly as it is.
 */
final readonly class RosterContentProvider implements ContentProviderInterface
{
    /**
     * WHAT EACH POST ASKS FOR, walked round the area's posts in order. The
     * empty one is deliberate and is the point of the list: a post on the
     * books that asks for no watch is a real state, and it is the one an
     * all-alike demo never produces.
     *
     * @var list<list<string>>
     */
    private const array DEMANDS = [
        ['day', 'night'],
        ['day'],
        ['day', 'night'],
        [],
        ['day', 'night'],
        ['office'],
        ['day', 'night'],
        ['day'],
    ];

    /**
     * THE RINGS, walked round the posts the same way. A ring is read as the
     * run of days one person works before the next takes over, so
     * `day night off` is the classic two-on-one-off and the longer ones are
     * the patterns a park with a bigger pool actually runs.
     *
     * @var list<list<string>>
     */
    private const array RINGS = [
        ['day', 'night', Cycle::OFF],
        ['day', 'day', Cycle::OFF],
        ['day', 'night', Cycle::OFF, Cycle::OFF],
        ['day', 'day', 'night', Cycle::OFF],
    ];

    /**
     * HOW FAR AHEAD A DEMO RING IS PUBLISHED. Long enough that the month
     * after this one is not blank the moment somebody steps forward a
     * page, short enough to stay a plausible planning horizon rather than
     * a year of guesses.
     */
    private const int HORIZON_DAYS = 45;

    public function __construct(
        private AreaOfInterestRepository $areas,
        private StationRepository $stations,
        private PostingRepository $postings,
        private DutyRepository $duties,
        private StationWatchRepository $rosteredPosts,
        private AbsenceRepository $absences,
        private ShiftVocabularyService $vocabulary,
        private StationWatchService $watches,
        private RotationGenerator $generator,
        private SwapService $swaps,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'roster';
    }

    public function label(): string
    {
        return 'Roster';
    }

    public function description(): string
    {
        return 'Watches, rings and a month of duties at the demo areas\' posts, with absences and a trade in flight.';
    }

    /**
     * The posts have to stand and be staffed before anybody can be rostered
     * onto them, and the people have to exist before they can be posted.
     */
    public function dependsOn(): array
    {
        return ['area', 'team', 'zone', 'station'];
    }

    public function load(): void
    {
        foreach ($this->areas->findAll() as $area) {
            $this->loadArea($area);
        }
    }

    private function loadArea(AreaOfInterest $area): void
    {
        $posts = $this->stations->findBy(['area' => $area], ['code' => 'ASC']);
        if ([] === $posts) {
            // NO POSTS, NO ROSTER. An area nobody stands at is not a roster
            // with nothing on it; it is an area this module has nothing to
            // say about, and seeding a vocabulary into it would put a
            // configure page's worth of content behind an empty tab.
            return;
        }

        // IDEMPOTENCE IS PER THING, NOT PER AREA, and that is a correction
        // worth stating. The first cut skipped a whole area that already
        // had anything on its roster — and the one area the module was
        // actually switched on for had a SINGLE post configured by hand,
        // so it stayed empty through every run. Each post, each absence
        // and each trade is now asked for separately: what somebody has
        // configured is left exactly as it is, and what nobody has is
        // filled in beside it.

        $this->seedVocabulary($area);

        $rings = [];

        // WHO IS ALREADY IN SOMEBODY ELSE'S RING. A person may be posted
        // at several posts, so without this two rings both draw them and
        // the same ranger stands two watches on one morning.
        $ringed = [];

        foreach ($posts as $index => $post) {
            $rotation = $this->putOnTheRoster($post, $index, $ringed);
            if (null !== $rotation) {
                $rings[] = $rotation;
            }
        }

        $this->seedAbsences($area);

        // THE RINGS RUN AFTER THE ABSENCES, because the generator skips a
        // day somebody is away — which is the behaviour worth showing, and
        // it can only show if the absence is already on the books.
        foreach ($rings as $rotation) {
            $this->generator->generate($rotation, $this->monthStart(), $this->monthEnd());
        }

        $this->seedSwaps($area);
    }

    /**
     * THE SHIFTS A PARK RUNS, asked for rather than written out here. The
     * module's own vocabulary service seeds an area that has never had one,
     * and a demo that listed the four shifts itself would be a second copy
     * of a default that already has an owner — and would go on seeding the
     * old four the day somebody changed them.
     */
    private function seedVocabulary(AreaOfInterest $area): void
    {
        $this->vocabulary->forArea($area);
    }

    /**
     * ONE POST ONTO THE ROSTER: what it asks for, and the ring of the
     * people posted there that answers it.
     *
     * A POST THAT ASKS FOR NOTHING GETS NO RING. There is nothing for a
     * rotation to generate, and one standing there with an empty demand
     * would be a ring that quietly produces no duties — which reads on
     * every page as a bug rather than as the deliberate state it is.
     */
    /**
     * @param array<string, true> $ringed people already drawn by an earlier post, added to here
     */
    private function putOnTheRoster(Station $post, int $index, array &$ringed): ?Rotation
    {
        $demands = self::DEMANDS[$index % \count(self::DEMANDS)];

        if (null !== $this->rosteredPosts->findOneForStation($post)) {
            // SOMEBODY'S OWN WORK, OR THIS SEEDER'S FROM AN EARLIER RUN.
            // Either way the post already says what it asks for, and
            // saying it again would either change nothing or overwrite a
            // decision with a demo.
            return null;
        }

        $watch = $this->watches->addToRoster($post);
        $this->watches->save(
            $watch,
            $demands,
            $watch->getSilenceWindowMinutes(),
            $watch->getOfflineAfterMinutes(),
            $watch->getCatchmentMetres(),
        );

        if ([] === $demands) {
            return null;
        }

        $posted = [];
        foreach ($this->postings->findStandingByStation($post) as $posting) {
            $person = $posting->getPerson();
            if ($person instanceof UserInterface) {
                $posted[] = $person;
            }
        }

        // ONE PERSON, ONE POST — in the demo, where nothing forces it.
        //
        // A ranger is often POSTED at more than one post, and each post's
        // ring draws from the people posted there, so two rings sharing a
        // person put them on two watches the same morning: Today showed
        // one ranger "watch 1 of 2" at two posts and twenty-four hours on
        // duty in a twenty-four hour day. Nothing in the product forbids
        // it — two independent per-post rings genuinely can double-book
        // somebody, which is worth knowing — but a DEMO that shows it is
        // showing a roster no park would publish. So a post takes the
        // people nobody has ringed yet.
        $pool = array_values(array_filter(
            $posted,
            static fn (UserInterface $person): bool => !isset($ringed[(string) $person->getUuidString()]),
        ));

        if ([] === $pool) {
            // NOBODY LEFT, OR NOBODY POSTED HERE AT ALL. Both are a post
            // without a ring, and the pages draw it as the hole it is —
            // which is better than borrowing somebody already spoken for
            // and calling the result a roster.
            return null;
        }

        foreach ($pool as $person) {
            $ringed[(string) $person->getUuidString()] = true;
        }

        $ring = self::RINGS[$index % \count(self::RINGS)];
        $slots = [];
        foreach ($demands as $shiftKey) {
            $slots[$shiftKey] = 1;
        }

        // A RING THAT DOES NOT ASK FOR WHAT THE POST NEEDS generates
        // nothing for the shift it leaves out, so the ring is narrowed to
        // the post's own demands and the classic day/night pair is kept
        // only where the post actually stands a night.
        $ring = array_values(array_filter(
            $ring,
            static fn (string $position): bool => Cycle::OFF === $position || \in_array($position, $demands, true),
        ));
        if ([] === array_filter($ring, static fn (string $p): bool => Cycle::OFF !== $p)) {
            $ring = [$demands[0], Cycle::OFF];
        }

        $rotation = new Rotation(
            $post->getArea() ?? throw new \LogicException('A post always belongs to an area.'),
            RotationScope::Post,
            Cycle::of($ring),
            $this->monthStart(),
            $slots,
            self::HORIZON_DAYS,
        )->standAt($post);

        $this->entityManager->persist($rotation);

        foreach ($pool as $position => $person) {
            $this->entityManager->persist(new RotationPoolMember($rotation, $person, $position));
        }

        $this->entityManager->flush();

        return $rotation;
    }

    /**
     * SOMEBODY IS ALWAYS AWAY. Three people, three reasons and three
     * lengths, spread across the month so the generator's skip is visible
     * in the grid rather than tucked into a corner of it.
     */
    private function seedAbsences(AreaOfInterest $area): void
    {
        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $person = $posting->getPerson();
            if ($person instanceof UserInterface) {
                $people[(string) $person->getUuidString()] = $person;
            }
        }

        $people = array_values($people);
        if ([] === $people) {
            return;
        }

        if ([] !== $this->absences->findOverlapping($area, $this->monthStart(), $this->monthEnd())) {
            // SOMEBODY IS ALREADY DOWN AS AWAY THIS MONTH. Three more would
            // be three more on every run.
            return;
        }

        $script = [
            [AbsenceKind::Leave, 2, 8],
            [AbsenceKind::Sick, 11, 13],
            [AbsenceKind::Course, 18, 22],
        ];

        foreach ($script as $index => [$kind, $fromDay, $throughDay]) {
            $person = $people[$index % \count($people)];

            $this->entityManager->persist(new Absence(
                $area,
                $person,
                $this->dayOfMonth($fromDay),
                $this->dayOfMonth($throughDay),
                $kind,
                $people[($index + 1) % \count($people)],
            ));
        }

        $this->entityManager->flush();
    }

    /**
     * A TRADE IN FLIGHT AND A TRADE ALREADY ANSWERED, both inside the
     * fortnight the grid draws — the open one is what marks two cells,
     * and the answered one is what keeps the swaps card from reading as
     * though a trade only ever has one state.
     */
    private function seedSwaps(AreaOfInterest $area): void
    {
        if ([] !== $this->swaps->recentBetween($area, $this->monthStart(), $this->monthEnd(), 1)) {
            // A TRADE IS ALREADY ON THE BOOKS this month, so the card reads
            // and a second pair would be a second pair every run.
            return;
        }

        $duties = $this->duties->findByAreaBetween($area, new \DateTimeImmutable('today'), new \DateTimeImmutable('today')->modify('+10 days'));
        if (\count($duties) < 2) {
            return;
        }

        $offered = $this->someoneElse($duties[0], $duties);
        if (null !== $offered) {
            $this->swaps->offer($duties[0], $offered, $duties[0]->getPerson());
        }

        $answered = $duties[\count($duties) - 1];
        $taker = $this->someoneElse($answered, $duties);
        if (null !== $taker) {
            $this->swaps->decline(
                $this->swaps->offer($answered, $taker, $answered->getPerson()),
                new \DateTimeImmutable('today'),
            );
        }
    }

    /**
     * SOMEBODY OTHER THAN THE PERSON STANDING IT — a trade needs two, and
     * a swap offered to the person already on the watch is not a trade.
     *
     * @param list<Duty> $duties
     */
    private function someoneElse(Duty $duty, array $duties): ?UserInterface
    {
        $standing = $duty->getPerson()->getUuidString();
        foreach ($duties as $other) {
            if ($other->getPerson()->getUuidString() !== $standing) {
                return $other->getPerson();
            }
        }

        return null;
    }

    private function monthStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month')->setTime(0, 0);
    }

    private function monthEnd(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('last day of this month')->setTime(0, 0);
    }

    /** A day of THIS month, clamped to a month that has fewer of them. */
    private function dayOfMonth(int $day): \DateTimeImmutable
    {
        $last = (int) $this->monthEnd()->format('j');

        return $this->monthStart()->modify(\sprintf('+%d days', min($day, $last) - 1));
    }
}
