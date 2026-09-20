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

namespace Uhifadhi\Roster\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE PLANNER'S TAB, OVER REAL HTTP.
 *
 * THE GEOMETRY CHANGED and these tests changed with it: the grid was posts
 * down over a week and is now PEOPLE DOWN, GROUPED BY POST, ACROSS A
 * FORTNIGHT. A test still asserting the old shape would be a test defending
 * a design nobody ships.
 *
 * What did NOT change is what the tab is for: a hole has to be visible
 * before the day arrives, and the figures above the grid have to measure the
 * same fortnight the grid draws.
 */
final class WeekTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);
    }

    private function signIn(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    private function url(string $from = '2026-09-14'): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/roster/week?from='.$from;
    }

    /**
     * A gate asking for two on the day watch, every day of the week, with
     * `$onEachDay` people actually on it.
     *
     * THE WHOLE WEEK IS FILLED, not just the monday: the grid draws seven
     * days, so a fixture that wrote one day would make six holes and the
     * test would be asserting against its own gap rather than the code's.
     */
    private function aGateAskingForTwo(int $onEachDay): Rotation
    {
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate)->expect(['day']);

        $rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day']),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 2],
            42,
        )->standAt($this->gate);
        $this->em->persist($rotation);

        for ($i = 0; $i < $onEachDay; ++$i) {
            $person = new User()->setPassword('x')->setEmail('ranger'.$i.'@example.test')->setFirstName('R'.$i)->setLastName('Example');
            $this->em->persist($person);
            $this->em->persist(new RotationPoolMember($rotation, $person, $i));

            for ($day = new \DateTimeImmutable('2026-09-14'); $day <= new \DateTimeImmutable('2026-09-20'); $day = $day->modify('+1 day')) {
                $this->em->persist(new Duty($this->area, $this->gate, $person, 'day', $day));
            }
        }

        $this->em->flush();

        return $rotation;
    }

    public function testTheRotaRendersAsAHouseCard(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        // The house card, not the banded one: banding is for register and
        // configure cards, ruled 2026-09-20.
        self::assertSame(0, $crawler->filter('.rband')->count(), 'A tab must not wear the banded card.');
        self::assertGreaterThan(0, $crawler->filter('.c > .tab')->count());
        self::assertSame(1, $crawler->filter('.factband')->count());
    }

    /** One quiet door per card, and it is a link. */
    public function testTheGridCarriesOneQuietDoorAndNoButtonCluster(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertSame(1, $crawler->filter('.c > a.more')->count());
    }

    /**
     * PEOPLE DOWN, GROUPED BY POST — the geometry the rebuild is about. A
     * grid of posts cannot answer "who is working too many nights"; a grid
     * of people can, because the answer is one row long.
     */
    public function testTheGridIsPeopleDownGroupedByPost(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        // A heading row for the post, then a row per person its ring draws.
        self::assertSame(1, $crawler->filter('.fg-rota td.sep')->count());
        self::assertStringContainsString('north gate post', $crawler->filter('.fg-rota td.sep')->text());
        self::assertSame(2, $crawler->filter('.fg-rota td.who')->count(), 'One row per person in the ring.');
    }

    /** A FORTNIGHT: fourteen day columns beside the ranger column. */
    public function testTheGridDrawsAFortnight(): void
    {
        $this->aGateAskingForTwo(1);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertSame(15, $crawler->filter('.fg-rota th')->count(), 'The ranger column and fourteen days.');
    }

    /**
     * THE ASSERTION THE WHOLE TAB EXISTS FOR — a hole is visible before the
     * day arrives. The gate asks for two and one person stands it, so the
     * figures say so and the unfilled list names them.
     */
    public function testAShortWatchIsVisibleBeforeTheDayArrives(): void
    {
        $this->aGateAskingForTwo(1);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Holes', $text);
        self::assertStringContainsString('1 short', $text);
    }

    /** Every cell of the fortnight is drawn, and a day with no watch is off. */
    public function testADayWithNoWatchIsDrawnAsOffAndNeverLeftBlank(): void
    {
        $this->aGateAskingForTwo(1);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertGreaterThan(0, $crawler->filter('.fg-cell.o')->count(), 'A stood-down day is stated, never blank.');
        self::assertGreaterThan(0, $crawler->filter('.fg-cell.d')->count());
    }

    /**
     * THE FIVE FIGURES measure the same fortnight the grid draws — a strip
     * counting a different window is a strip nobody can check against the
     * picture under it.
     */
    public function testTheFiguresSitAboveTheGrid(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());
        $text = $crawler->filter('body')->text();

        foreach (['Person-watches', 'Holes', 'Nights, the heaviest', 'Swaps pending'] as $figure) {
            self::assertStringContainsString($figure, $text);
        }

        // AND "FLAGGED TODAY" IS NOT ONE OF THEM. A row is four cards
        // (ruled), and this is the PLANNER's tab: a flag is today's
        // problem, which the agenda and the day board both lead with. A
        // fortnight's strip carrying a figure that changes every morning
        // would be the one thing on this page not about the fortnight.
        self::assertStringNotContainsString('Flagged today', $text);
    }

    /** The holes list names the post, the day and how short it is. */
    public function testTheHolesAreListedSoonestFirst(): void
    {
        $this->aGateAskingForTwo(1);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Unfilled watches', $text);
        self::assertStringContainsString('north gate post', $text);
        self::assertStringContainsString('1 short', $text);
        // A card never grows with its data: eight shown, the count stated.
        self::assertStringContainsString('this fortnight', $text);
    }

    /**
     * THE GRID STARTS ON A MONDAY whatever day is asked for. A week that
     * began on the day you happened to look is not a week anybody plans in.
     */
    public function testTheFortnightStartsOnAMondayWhateverDayIsAskedFor(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        // Thursday 17 September 2026.
        $crawler = $this->client->request('GET', $this->url('2026-09-17'));

        $headings = $crawler->filter('.fg-rota th')->each(static fn ($th): string => trim($th->text()));
        self::assertSame('ranger', $headings[0]);
        self::assertStringContainsString('mon', $headings[1]);
        self::assertStringContainsString('14', $headings[1]);
        // A fortnight, so the last column is the sunday thirteen days on.
        self::assertStringContainsString('27', $headings[14]);
    }

    /** A mistyped date in a url is not worth a 500. */
    public function testAnUnreadableWeekFallsBackRatherThanFailing(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/week?from=neverday');

        self::assertResponseIsSuccessful();
    }

    /** With no post on the books the grid says so rather than drawing an empty table. */
    public function testAnAreaWithNoPostOnTheBooksSaysSo(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No post is on the roster’s books', $crawler->filter('body')->text());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
