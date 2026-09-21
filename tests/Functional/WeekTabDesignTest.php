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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE WEEK, MEASURED AGAINST ITS DESIGN — modules/roster/week.html, ruled
 * 20–21 sep and graduated from three rounds of options.
 *
 * WHAT THIS TEST IS FOR is the chrome rather than the grid: the band, the
 * fill row and its read-only caption, and above all THE SHEET'S HEAD, which
 * carries every control the sheet has on one baseline. Owner: "this entire
 * thing needs to be in the sheet header — I didn't even see it exists." A
 * control that drifts back out of the head is the design coming undone, and
 * nothing about the data would notice.
 */
final class WeekTabDesignTest extends WebTestCase
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
        $ranger = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->em->persist($ranger);
        $this->em->flush();

        $this->em->persist(
            new Posting()->setStation($gate)->setPerson($ranger)
                ->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
        );
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);

        // A STATION ON THE BOOKS AND A PATTERN TO FILL IT FROM, because the
        // fill row is only drawn where there is something to fill.
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($gate)->expect(['day', 'night']);

        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);
        $patterns->create($this->area, Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]));
        $this->em->flush();
    }

    private function open(): Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/week');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * THE BAND IS THE DESIGN'S FOUR FIGURES, in its order — and every one
     * of them measures the window the sheet under it draws.
     */
    public function testTheBandDrawsTheDesignsFourFigures(): void
    {
        $band = $this->open()->filter('.factband');

        self::assertCount(1, $band);
        self::assertSame(
            ['Rangers', 'Days planned', 'Unfilled', 'Edited by hand'],
            $band->filter('.f .k')->each(static fn (Crawler $cell): string => trim($cell->text())),
        );
        self::assertSame('Roster settings →', html_entity_decode(trim($band->filter('a.more')->text())));

        // A KPI ROW IS FOUR, never five — and there is no second idiom on
        // the page: the band or the cards, never both.
        self::assertCount(0, $this->open()->filter('.kstrip'));
    }

    /**
     * THE FILL ROW IS THE ONLY THING ABOVE THE SHEET CARD (layout C), and
     * it carries exactly the design's parts.
     */
    public function testTheFillRowIsTheOnlyThingAboveTheSheet(): void
    {
        $row = $this->open()->filter('.prow');

        self::assertCount(1, $row);
        self::assertStringContainsString('Fill from a pattern', $row->text());
        self::assertCount(3, $row->filter('select.fld'), 'The station, the pattern and the start date.');
        self::assertCount(1, $row->filter('.pstrip.lg'), 'The chosen cycle, drawn.');
        self::assertSame('Preview', trim($row->filter('.pact .btn')->text()));
        self::assertSame('Fill these days', trim($row->filter('.pact .cta')->text()));
    }

    /**
     * AND UNDER IT, WHAT A FILL OBEYS — read-only, with one door, to the
     * card that sets it. RULED 21 sep: nothing here is a control.
     */
    public function testTheFillRowStatesTheRulesAndDoesNotOfferThem(): void
    {
        $caption = $this->open()->filter('.prow2 .pfills');

        self::assertCount(1, $caption);

        $text = html_entity_decode($caption->text());
        self::assertStringContainsString('fills 6 weeks ahead', $text);
        self::assertStringContainsString('11 hours rest', $text);
        self::assertStringContainsString('no night then day', $text);

        self::assertCount(0, $caption->filter('input, select, button'), 'A caption, never a control.');
        self::assertCount(1, $caption->filter('a'), 'One door, and it goes to Watches.');
        self::assertStringContainsString('/watches', (string) $caption->filter('a')->attr('href'));
    }

    /**
     * THE SHEET'S HEAD CARRIES EVERY CONTROL THE SHEET HAS, on one
     * baseline: the window navigation, Today, the station filter, the
     * weeks chip and the fold pair.
     */
    public function testTheSheetsHeadCarriesEveryControl(): void
    {
        $head = $this->open()->filter('.sheetcard .rb-hd.shead');

        self::assertCount(1, $head);
        self::assertSame('The sheet', trim($head->filter('.tab')->text()));
        self::assertStringContainsString('across 1 station', $head->filter('.rb-ttl')->text());

        // The calendar's own nav: two arrows, the window, and Today.
        $nav = $head->filter('.shnav');
        self::assertCount(3, $nav->filter('a.mchip'));
        self::assertCount(1, $nav->filter('span.mchip.on'), 'The window is the one `on` chip.');
        self::assertSame('Today', trim($nav->filter('a.mchip')->eq(2)->text()));

        // Two grouped dropdowns, and the weeks chip offers one, two, four.
        self::assertCount(2, $head->filter('details.i-dd'));
        self::assertSame(
            ['One week', 'Two weeks', 'Four weeks'],
            $head->filter('.i-ddmenu[aria-label="weeks in view"] .i-ddopt-l')
                ->each(static fn (Crawler $option): string => trim($option->text())),
        );

        self::assertSame(
            ['Fold all', 'Open all'],
            $head->filter('button.mchip')->each(static fn (Crawler $chip): string => trim($chip->text())),
        );
    }

    /** THE SHEET SCROLLS INSIDE ITS OWN BOUNDED CARD, with the days pinned. */
    public function testTheSheetScrollsInsideItsCard(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('.c.rband.sheetcard'));
        self::assertCount(1, $crawler->filter('.sheetcard .rb-body .psheetwrap.w2'));
        self::assertCount(1, $crawler->filter('.psheetwrap > table.csheet > thead'));
    }

    /** AND THE KEY UNDER IT NAMES EVERY MARK THE SHEET CAN DRAW. */
    public function testTheKeyNamesEveryMark(): void
    {
        $key = $this->open()->filter('.pkey');

        self::assertCount(1, $key);

        $text = html_entity_decode($key->text());
        foreach (['off — nothing drawn', 'unfilled', 'edited by hand'] as $mark) {
            self::assertStringContainsString($mark, $text);
        }

        self::assertGreaterThan(0, $key->filter('i.k[data-cat]')->count(), 'A shift\'s colour is the shift\'s.');
    }

    /** THE SWAP SECTION IS LABELLED and is two house cards. */
    public function testTheSwapSectionIsLabelled(): void
    {
        $crawler = $this->open();

        self::assertContains(
            'The swap in progress',
            $crawler->filter('h2.zone')->each(static fn (Crawler $h): string => html_entity_decode(trim($h->text()))),
        );
        self::assertCount(2, $crawler->filter('.grid.g2 > .c'));
    }

    /**
     * AND THE OLD RENDERING IS GONE. The rota card, its "The rotation"
     * door and the separate unfilled-watches register were the shape
     * before the sheet; leaving any of them would be shipping two
     * answers to one question.
     */
    public function testTheOldRotaRenderingIsGone(): void
    {
        $text = $this->open()->filter('body')->text();

        self::assertStringNotContainsString('The rotation →', html_entity_decode($text));
        self::assertStringNotContainsString('Unfilled watches', $text);
        self::assertCount(0, $this->open()->filter('.fg-cell'));
    }
}
