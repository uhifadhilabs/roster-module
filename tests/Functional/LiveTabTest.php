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

    /** THE FIVE KPI CARDS the design names, in its order. */
    public function testTheStripDrawsTheDesignsFiveCards(): void
    {
        $crawler = $this->open();

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(5, $cards);

        self::assertSame(
            ['positions-now', 'verified', 'away', 'oldest-ping', 'posts-reporting'],
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

        self::assertCount(1, $crawler->filter('.r-live .r-live-plate'), 'The atlas plate, in the card that leads the page.');
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
     * THE RAIL LISTS THE PEOPLE THE ROSTER HAS ON A WATCH, whether or not a
     * handset has said where they are — and the group for those with no
     * position is the one it ends on.
     */
    public function testTheRailListsEverybodyIncludingThoseWithNoPosition(): void
    {
        $crawler = $this->open();

        $rail = $crawler->filter('.r-live-rail');
        self::assertCount(1, $rail);

        self::assertStringContainsString('Ada Example', $rail->text(), 'Somebody rostered is on the list…');
        self::assertStringContainsString('no position', $rail->text(), '…under the heading for having sent none.');
        self::assertStringContainsString('no ping', $rail->text());
    }

    /**
     * THE LEGEND CARRIES THIS MODULE'S OWN GROUP, and its swatches are
     * TOKEN NAMES. A module declares no colour — not even mirrored as a
     * literal, and not even at a seam JavaScript reads.
     */
    public function testThePresenceLayersPublishTokenNamesAndNeverAColour(): void
    {
        $crawler = $this->open();

        $plate = $crawler->filter('.r-live-plate')->html();

        self::assertStringContainsString('Roster presence states', html_entity_decode($plate), 'This module\'s own legend group.');
        self::assertStringContainsString('var(--plate-', $plate, 'Swatches are the plate palette\'s token names.');
        self::assertDoesNotMatchRegularExpression(
            '/roster\.presence\.[a-z_.]+[^}]{0,400}#[0-9A-Fa-f]{6}/',
            $plate,
            'No hex anywhere near this module\'s layers.',
        );
    }

    /** AND THE MODULE'S OWN SOURCE CARRIES NO COLOUR EITHER. */
    public function testTheModuleDeclaresNoColourInItsLiveService(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Service/RosterLiveService.php');

        self::assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $source, 'A module declares no colour.');
        self::assertStringContainsString('var(--plate-ok)', $source, 'It publishes the token name and lets the plate resolve it.');
    }
}
