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
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * LIVE, MEASURED AGAINST ITS DESIGN — modules/roster/live.html.
 *
 * THE PLATE IS FIRST and the roster is underneath it, which is the whole
 * shape of the tab and was the thing the port had backwards: it used to be
 * the roster and nothing else.
 *
 * WHAT THIS MODULE CONTRIBUTES IS ASSERTED, and nothing the atlas or the
 * area owns is. The plate's imagery, controls and key are the house's; the
 * ground, the boundary and the posts are the area's; the marker layers and
 * their legend group are the roster's, and so is the rail beside them.
 *
 * THE RAIL AND THE MAP ARE DELIBERATELY NOT THE SAME SET. Somebody at their
 * post whose phone has said nothing is in the rail's last group and nowhere
 * on the map — a surface showing only the map would report them absent, and
 * one inventing a marker at the post would turn a claim into proof.
 */ final class LiveTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

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

        $crawler = $this->client->request('GET', $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** THE FOUR KPI CARDS the design names, in its order — four, never five. */
    public function testTheStripDrawsTheDesignsFourCards(): void
    {
        $crawler = $this->open();

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(4, $cards, 'A KPI row is four cards, never five.');

        self::assertSame(
            ['positions-now', 'verified', 'oldest-ping', 'posts-reporting'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /** THE PLATE IS FIRST, and the roster is underneath it. */
    public function testThePlateComesFirstAndTheRosterIsUnderneath(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['The plate first', 'The roster, underneath'],
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => html_entity_decode(trim($h->text()))),
        );

        self::assertCount(1, $crawler->filter('.fg-live'), 'The plate and its rail, side by side, in the card that leads the page.');
        self::assertGreaterThan(0, $crawler->filter('.fg-live .map-plate')->count(), 'And the atlas plate itself is in it.');
    }

    /** THE FILTER ROW, the house's, pointed at this page. */
    public function testItWearsTheHouseFilterRow(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd'));
        self::assertStringContainsString('/live', (string) $row->attr('action'));
    }

    /**
     * THE RAIL IS HEAD + BODY + FOOT, AND ONLY THE BODY SCROLLS.
     *
     * AN OVERFLOW BOX CLIPS EVERYTHING POSITIONED INSIDE IT, so a head, a
     * popover or a tooltip parented to a scrolling rail is cut off the
     * moment the lists are long enough to scroll — which on this tab is
     * always. The head and the foot therefore sit OUTSIDE the scroller;
     * this asserts that arrangement structurally, because the clipping
     * itself is a computed-layout fact and yours to see.
     */
    public function testTheRailIsAPinnedHeadAScrollingBodyAndAPinnedFoot(): void
    {
        $crawler = $this->open();

        $rail = $crawler->filter('.fg-side');
        self::assertCount(1, $rail);

        self::assertCount(1, $crawler->filter('.fg-side > .fg-rlhd'), 'The head is a child of the rail, not of the scroller.');
        self::assertCount(1, $crawler->filter('.fg-side > .rl-body'), 'One scroller.');
        self::assertCount(1, $crawler->filter('.fg-side > .fg-foot'), 'And the foot is outside it too.');

        self::assertCount(0, $rail->filter('.rl-body .fg-rlhd'), 'Nothing pinned may sit inside the scroller.');
        self::assertCount(0, $rail->filter('.rl-body .fg-foot'));
    }

    /**
     * THE RAIL IS A WIDGET SURFACE: the lists it carries are widgets, and
     * the shipped arrangement is two of the three.
     */
    public function testTheRailCarriesTheListsTheSurfaceShipsWith(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['people', 'stations'],
            $crawler->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
            'The duty officer: people first, posts under them — and zones one adopt away.',
        );

        self::assertCount(3, $crawler->filter('.rl-presets .mchip'), 'Three arrangements to choose between.');
        self::assertCount(1, $crawler->filter('.rl-presets .mchip.on'), 'One of them is in force.');
    }

    /**
     * A PRESET VISIBLY CHANGES WHICH LISTS SHOW AND THEIR ORDER, and the
     * choice is REMEMBERED — in the same store the Overview surface uses,
     * so a duty officer's rail is the rail they left.
     */
    public function testAdoptingAnArrangementChangesTheRailAndIsRemembered(): void
    {
        $crawler = $this->open();

        $everything = $crawler->filter('.rl-presets .mchip')->reduce(
            static fn (\Symfony\Component\DomCrawler\Crawler $chip): bool => 'Everything' === trim($chip->text()),
        );
        self::assertCount(1, $everything);

        $this->client->request('GET', (string) $everything->attr('href'));
        self::assertResponseRedirects();
        $after = $this->client->followRedirect();

        self::assertSame(
            ['people', 'stations', 'zones'],
            $after->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
            'All three lists, in the order the arrangement names.',
        );

        // AND IT PERSISTS: a fresh request reads the same rail back out of
        // the store rather than falling back to what the module ships.
        $again = $this->open();
        self::assertSame(
            ['people', 'stations', 'zones'],
            $again->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
        );
        self::assertSame('Everything', trim($again->filter('.rl-presets .mchip.on')->text()));
    }

    /**
     * THE STATIONS LIST IS ONE ROW PER POST, and a post the plate cannot
     * be centred on is INERT AND UNMARKED rather than a link that does
     * nothing when clicked.
     */
    public function testTheStationsListCarriesTheDesignsColumns(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('[data-list="stations"] .fg-stn')->eq(0);
        self::assertCount(1, $row->filter('.nm'), 'The post, and its code and people under it.');
        self::assertCount(1, $row->filter('.nm em'));
        self::assertCount(1, $row->filter('.lv'), 'How many of them are live now.');

        self::assertStringContainsString('ST-01', $row->filter('.nm em')->text());
        self::assertStringContainsString('live', $row->filter('.lv')->text());
    }

    /** THE DOOR IS THE LIBRARY, in the design's exact words. */
    public function testTheRailsDoorIsTheWidgetLibrary(): void
    {
        $crawler = $this->open();

        // THE HOUSE'S `.more` IDIOM, not a door class of this module's:
        // one quiet door on a card looks the same everywhere in the app.
        $door = $crawler->filter('.fg-side .fg-rlhd .more');
        self::assertCount(1, $door);
        self::assertSame('Widget library →', html_entity_decode(trim($door->text())));
        self::assertStringContainsString('/widgets', (string) $door->attr('href'));
    }

    /** AND THE FOOT SAYS WHICH ARRANGEMENT IS IN FORCE, and whose it is. */
    public function testTheFootMarksTheArrangementInForce(): void
    {
        $crawler = $this->open();

        self::assertStringContainsString('default ·', html_entity_decode($crawler->filter('.fg-side .fg-foot')->text()));
    }

    /**
     * THE LEGEND CARRIES THE LIVE KEY, and the module named none of it.
     *
     * A LAYER SHIPS A LEGEND — and here the legend says more than the
     * layer can: the mark has a state the plate draws DIMMED and a state
     * it draws NOWHERE, and a plate silent about the people it is not
     * showing would be claiming the park is fully seen. The atlas adds
     * both in one call.
     */
    public function testTheLiveKeyIsOnThePlateAndTheModuleNamesNoneOfIt(): void
    {
        $crawler = $this->open();

        $plate = html_entity_decode($crawler->filter('.fg-live')->html());

        foreach (['Live position', 'Stale', 'No position'] as $row) {
            self::assertStringContainsString($row, $plate, 'The key the live layer ships with.');
        }

        // AND NO COLOUR ANYWHERE NEAR THIS MODULE'S OWN LAYER. The posts
        // layer is still the roster's; it must not have grown a palette.
        self::assertDoesNotMatchRegularExpression('/roster\.[a-z_.]+[^}]{0,300}#[0-9A-Fa-f]{6}/', $plate);
    }

    /** AND THE MODULE'S OWN SOURCE CARRIES NO COLOUR EITHER. */
    public function testTheModuleDeclaresNoColourInItsLiveService(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Service/RosterLiveService.php');

        self::assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $source, 'A module declares no colour.');
        self::assertStringContainsString('var(--plate-ok)', $source, 'It publishes the token name and lets the plate resolve it.');
    }
}
