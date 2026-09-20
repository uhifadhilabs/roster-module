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
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE WEEK, MEASURED AGAINST ITS DESIGN — modules/roster/week.html.
 *
 * The planner's tab: the band, the filter row, five figures, and the rota
 * as people down and days across with every hole drawn as a hole.
 *
 * WHAT THIS TEST IS FOR is the chrome the port was missing rather than the
 * grid, which was already right: the filter row, the section label, the
 * caption's own facts, and the figures' fragments — a count with no
 * fragment under it makes the reader open another tab to find out which
 * hole, and that is the one thing a planner should not have to do.
 */ final class WeekTabDesignTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

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

        $gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($gate);
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($gate)->expect(['day', 'night']);

        $rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', 'night', Cycle::OFF]),
            new \DateTimeImmutable('today'),
            ['day' => 1, 'night' => 1],
            42,
        )->standAt($gate);
        $this->em->persist($rotation);

        $ranger = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->em->persist($ranger);
        $this->em->persist(new RotationPoolMember($rotation, $ranger, 0));
        // Due today and nothing reported: the "no check-in" row the decisions
        // card exists for, and the one figure an empty demo never produces.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        // AND A NIGHT WATCH THAT BEGAN YESTERDAY, which is the case the
        // wall exists to draw: it is still standing at 05:00 this morning,
        // so it is the first block of today's row as well as the last of
        // yesterday's.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::WEEK_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * THE FOUR FIGURES the design names, in its order — four, never five.
     * "Flagged today" is deliberately not among them: this is the PLANNER's
     * tab, and a flag is today's problem, which two other tabs lead with.
     */
    public function testTheStripDrawsTheDesignsFourFigures(): void
    {
        $crawler = $this->open();

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(4, $cards, 'A KPI row is four cards, never five.');

        self::assertSame(
            ['person-watches', 'holes', 'heaviest-nights', 'swaps-pending'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );

        foreach ($cards as $card) {
            $one = new \Symfony\Component\DomCrawler\Crawler($card);
            self::assertCount(1, $one->filter('.sub'), 'Every figure says what it is about, not just how many.');
        }
    }

    /** THE FILTER ROW, the house's, pointed at this page. */
    public function testItWearsTheHouseFilterRow(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd'));
        self::assertCount(1, $row->filter('.lsearch input[name="q"]'));
        self::assertStringContainsString('/week', (string) $row->attr('action'));
    }

    /** THE SECTION IS LABELLED in the design's own words. */
    public function testTheRotaSectionIsLabelled(): void
    {
        $crawler = $this->open();

        self::assertContains(
            'People down, days across — the fortnight',
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => html_entity_decode(trim($h->text()))),
        );
    }

    /** THE CAPTION CARRIES THE WINDOW AND WHAT IS IN IT. */
    public function testTheCaptionCarriesTheWindowTheRangersAndTheDays(): void
    {
        $crawler = $this->open();

        $caption = html_entity_decode($crawler->filter('.c .tab .src')->eq(0)->text());

        self::assertMatchesRegularExpression('/\d+ ranger/', $caption);
        self::assertStringContainsString('14 days', $caption);
    }

    /** THE GRID IS PEOPLE DOWN AND FOURTEEN DAYS ACROSS. */
    public function testTheRotaIsPeopleDownAndAFortnightAcross(): void
    {
        $crawler = $this->open();

        $table = $crawler->filter('table.fg-rota');
        self::assertCount(1, $table);
        self::assertCount(14, $table->filter('tr')->eq(0)->filter('th:not(.who)'), 'A fortnight of columns.');
        self::assertGreaterThan(0, $table->filter('td.who')->count(), 'And a row per person.');
    }
}
