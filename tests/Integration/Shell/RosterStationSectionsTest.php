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

namespace Uhifadhi\Roster\Tests\Integration\Shell;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Shell\RosterStationSections;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THE ROSTER PUTS ON A POST — and, just as load-bearing, what it stays
 * silent about.
 */
final class RosterStationSectionsTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $onTheBooks;
    private Station $offTheBooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->onTheBooks = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->offTheBooks = $this->aStation($this->area, 'west outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->em->flush();

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->onTheBooks)->expect(['day', 'night']);
        $this->em->flush();
    }

    private function sections(): RosterStationSections
    {
        $sections = $this->service(RosterStationSections::class);
        self::assertInstanceOf(RosterStationSections::class, $sections);

        return $sections;
    }

    private function request(StationSurface $surface, Station ...$stations): StationSectionRequest
    {
        return new StationSectionRequest(
            array_map(
                fn (Station $station): StationRef => new StationRef(
                    (string) $station->getUuidString(),
                    (string) $this->area->getUuidString(),
                    (string) $station->getName(),
                ),
                array_values($stations),
            ),
            $surface,
        );
    }

    public function testItIsTheContractTheAreaCollects(): void
    {
        self::assertInstanceOf(StationSectionsInterface::class, $this->sections());
        self::assertSame(RosterModuleProvider::SLUG, $this->sections()->moduleSlug());
    }

    public function testItPutsAWatchAndPresenceBandOnTheRecord(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->onTheBooks));

        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::WATCH, $sections[0]->id);
        self::assertSame('Watch and presence', $sections[0]->label);
        self::assertSame('@UhifadhiRoster/station/_watch.html.twig', $sections[0]->template);
    }

    public function testItPutsARosterBlockOnTheConfigureCard(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks));

        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::ROSTER, $sections[0]->id);
        self::assertSame('Roster', $sections[0]->label);
        self::assertSame('@UhifadhiRoster/station/_configure.html.twig', $sections[0]->template);
    }

    /**
     * A POST THIS MODULE DOES NOT KEEP ON ITS BOOKS IS ANSWERED WITH SILENCE
     * — no key at all, so the area draws no band and no placeholder. It is
     * the difference between "not our post" and "our post, nothing to
     * report", and the contract keeps them apart deliberately.
     */
    public function testAPostOffTheBooksIsAnsweredWithSilenceAndNotAnEmptyBand(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->onTheBooks, $this->offTheBooks));

        self::assertSame([], $answer->forStation((string) $this->offTheBooks->getUuidString()));
        self::assertArrayNotHasKey((string) $this->offTheBooks->getUuidString(), $answer->byStation);
        self::assertNotSame([], $answer->forStation((string) $this->onTheBooks->getUuidString()));
    }

    /**
     * A POST THAT DECLARES NO WATCH SAYS SO — it is on the books, so it gets
     * a band, and the band states that it is never counted, never late and
     * never a hole. Staying silent about it would look like a band that
     * failed to load.
     */
    public function testAPostWithNoWatchGetsABandThatSaysSo(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->offTheBooks)->expect([]);
        $this->em->flush();

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->offTheBooks));
        $sections = $answer->forStation((string) $this->offTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertStringContainsString('never counted, never late and never a hole', (string) $sections[0]->summary);
    }

    /** The band offers a way to the one place a watch is edited, and no editor of its own. */
    public function testTheBandLinksToTheOnePlaceAWatchIsEdited(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks));
        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections[0]->actions);
        self::assertStringContainsString('/modules/roster/watches', $sections[0]->actions[0]->url);
    }

    /** An empty request is an empty answer, and never a query. */
    public function testAnEmptyRequestIsAnEmptyAnswer(): void
    {
        $answer = $this->sections()->sectionsFor(new StationSectionRequest([], StationSurface::Record));

        self::assertTrue($answer->isEmpty());
    }

    /**
     * THE WHOLE SET IN ONE CALL. The configure page draws a card per station,
     * so the answer has to be keyed by post rather than ordered — a
     * contributor that skipped one would otherwise shift every card after it
     * onto the wrong station.
     */
    public function testItAnswersASetKeyedByPost(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->offTheBooks);
        $this->em->flush();

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks, $this->offTheBooks));

        self::assertArrayHasKey((string) $this->onTheBooks->getUuidString(), $answer->byStation);
        self::assertArrayHasKey((string) $this->offTheBooks->getUuidString(), $answer->byStation);
    }
}
