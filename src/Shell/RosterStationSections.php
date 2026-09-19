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

namespace Uhifadhi\Roster\Shell;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Area\StationAction;
use Uhifadhi\Contracts\Area\StationSection;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSections;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;

/**
 * WHAT THE ROSTER PUTS ON A POST — a *Watch and presence* band on the
 * station's record, and a *Roster* block on its card on the area's Stations
 * configure page.
 *
 * RULED 18 sep, and this class is the whole of what survived it. The station
 * is the AREA's: its name, kind, point, call sign, zone and postings all
 * belong there. What the roster keeps is the watch — which shifts the post
 * expects, how long its silence may run, how wide its catchment is — and the
 * presence derived from the people on it. Those are drawn INTO the area's own
 * pages rather than onto a page of this module's, which is why
 * `modules/roster/stations.html` was retired as a page.
 *
 * A POST THIS MODULE DOES NOT KEEP ON ITS BOOKS IS ANSWERED WITH SILENCE —
 * no key in the answer at all, so the area draws no band and no placeholder.
 * That is not the same as a post this module has nothing to REPORT about:
 * one is "not our post", the other is a band saying so in this module's own
 * words, and the contract keeps them apart deliberately.
 *
 * THE AREA DRAWS THE BAND AND THIS DRAWS WHAT IS IN IT. The card, the
 * heading row, the contributor tag and the summary line are the surface's
 * chrome, written once by the bundle that owns the page; the templates named
 * here write rows and nothing around them.
 *
 * BATCHED, and it matters: the configure page draws a card per station, so a
 * contributor asked once per card would run a query per card per module per
 * page. It is handed the whole set and asks its questions once.
 */
final readonly class RosterStationSections implements StationSectionsInterface
{
    /** The band on the post's own record. */
    public const string WATCH = 'watch';

    /** The block on the post's card on the area's Stations configure page. */
    public const string ROSTER = 'roster';

    public function __construct(
        private StationRepository $stations,
        private StationWatchService $watches,
        private ShiftVocabularyService $shifts,
        private PresenceReader $presence,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function moduleSlug(): string
    {
        return RosterModuleProvider::SLUG;
    }

    public function sectionsFor(StationSectionRequest $request): StationSections
    {
        if ($request->isEmpty()) {
            return StationSections::none();
        }

        $stations = $this->stationsByUuid($request->stationUuids());
        if ([] === $stations) {
            return StationSections::none();
        }

        $today = new \DateTimeImmutable('today');

        $byStation = [];
        foreach ($stations as $uuid => $station) {
            $watch = $this->watches->forStation($station);

            // NOT ON THE BOOKS, SO NOTHING IS SAID. The area draws no band,
            // which is the honest answer for a post this module does not
            // work — and the reason the other six of twelve are silent
            // rather than showing an empty watch.
            if (null === $watch) {
                continue;
            }

            $byStation[$uuid] = [
                StationSurface::Record === $request->surface
                    ? $this->recordBand($station, $watch, $today)
                    : $this->configureBlock($station, $watch),
            ];
        }

        return new StationSections($byStation);
    }

    /**
     * THE *WATCH AND PRESENCE* BAND on the post's record: what it expects,
     * and who is on it now against who was rostered.
     */
    private function recordBand(Station $station, StationWatch $watch, \DateTimeImmutable $today): StationSection
    {
        $presence = $this->presence->post($station, $today);
        $area = $station->getArea();

        return new StationSection(
            id: self::WATCH,
            label: 'Watch and presence',
            template: '@UhifadhiRoster/station/_watch.html.twig',
            variables: [
                'watch' => $watch,
                'presence' => $presence,
                'shifts' => $this->labelsFor($station),
                'day' => $today,
            ],
            summary: $this->summaryFor($watch, $presence?->shortfall() ?? 0),
            actions: null === $area ? [] : [
                new StationAction('The roster', $this->router->generate(
                    RosterConfigureController::WATCHES_ROUTE,
                    ['uuid' => (string) $area->getUuidString()],
                )),
            ],
        );
    }

    /**
     * THE *ROSTER* BLOCK on the post's configure card — the four columns
     * this module owns, read-only here. They are EDITED in one place, the
     * module's own Watches section, and this is the other door onto them: a
     * second editor would be a second validation and eventually a second
     * answer.
     */
    private function configureBlock(Station $station, StationWatch $watch): StationSection
    {
        $area = $station->getArea();

        return new StationSection(
            id: self::ROSTER,
            label: 'Roster',
            template: '@UhifadhiRoster/station/_configure.html.twig',
            variables: [
                'watch' => $watch,
                'shifts' => $this->labelsFor($station),
                'pool' => $this->watches->poolSizeFor($station),
            ],
            summary: 'The shifts this post expects, how long its silence may run, and how wide its catchment is.',
            actions: null === $area ? [] : [
                new StationAction('Edit on the roster', $this->router->generate(
                    RosterConfigureController::WATCHES_ROUTE,
                    ['uuid' => (string) $area->getUuidString()],
                )),
            ],
        );
    }

    /**
     * THE LINE BESIDE THE HEADING. A post that declares no watch says so
     * plainly — it is never counted, never late and never a hole, and a band
     * that stayed silent about it would look like a band that failed to
     * load.
     */
    private function summaryFor(StationWatch $watch, int $shortfall): string
    {
        if ($watch->expectsNothing()) {
            return 'This post declares no watch, so it is never counted, never late and never a hole — only offline.';
        }

        return 0 === $shortfall
            ? 'Who is due here today, and what the positions say about them.'
            : \sprintf('Who is due here today, and what the positions say about them. %d watch%s short.', $shortfall, 1 === $shortfall ? '' : 'es');
    }

    /**
     * The area's shift labels, keyed by the key a watch stores.
     *
     * @return array<string, string>
     */
    private function labelsFor(Station $station): array
    {
        $area = $station->getArea();
        if (null === $area) {
            return [];
        }

        $labels = [];
        foreach ($this->shifts->forArea($area) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        return $labels;
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, Station> keyed by uuid, in no particular order — the answer is keyed too
     */
    private function stationsByUuid(array $uuids): array
    {
        $valid = [];
        foreach ($uuids as $uuid) {
            if (Uuid::isValid($uuid)) {
                $valid[] = Uuid::fromString($uuid);
            }
        }

        if ([] === $valid) {
            return [];
        }

        $stations = [];
        foreach ($this->stations->findBy(['uuid' => $valid]) as $station) {
            $stations[(string) $station->getUuidString()] = $station;
        }

        return $stations;
    }
}
