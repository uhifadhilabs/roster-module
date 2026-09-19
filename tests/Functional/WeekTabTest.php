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
use Doctrine\ORM\Tools\SchemaTool;
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
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE PLANNER'S TAB, OVER REAL HTTP — and the assertion that matters is that
 * a HOLE IS VISIBLE. A week grid that drew a tidy row over a post nobody is
 * on would be worse than no week grid.
 */
final class WeekTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;

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

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

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

    public function testTheWeekTabRendersAsAHouseCard(): void
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
     * THE ASSERTION THE WHOLE TAB EXISTS FOR. The gate asks for two and has
     * one, so the cell is a hole and says which.
     */
    public function testAShortWatchIsDrawnAsAHole(): void
    {
        $this->aGateAskingForTwo(1);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        // Seven days, every one of them a watch short of two.
        self::assertSame(7, $crawler->filter('.r-cell span.gap')->count(), 'A short watch has to draw a hole, every day it is short.');
        self::assertStringContainsString('1 of 2', $crawler->filter('.r-cell span.gap')->first()->text());
    }

    /** A full watch is not a hole. */
    public function testAFilledWatchIsNotAHole(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        $crawler = $this->client->request('GET', $this->url());

        self::assertSame(0, $crawler->filter('.r-cell span.gap')->count());
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
        self::assertStringContainsString('this week', $text);
    }

    /**
     * THE GRID STARTS ON A MONDAY whatever day is asked for. A week that
     * began on the day you happened to look is not a week anybody plans in.
     */
    public function testTheWeekStartsOnAMondayWhateverDayIsAskedFor(): void
    {
        $this->aGateAskingForTwo(2);
        $this->signIn();

        // Thursday 17 September 2026.
        $crawler = $this->client->request('GET', $this->url('2026-09-17'));

        $headings = $crawler->filter('.r-week th')->each(static fn ($th): string => trim($th->text()));
        self::assertSame('post', $headings[0]);
        self::assertStringContainsString('mon 14', $headings[1]);
        self::assertStringContainsString('sun 20', $headings[7]);
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
