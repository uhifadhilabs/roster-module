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

namespace Uhifadhi\Roster\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Roster\Model\LiveFigures;
use Uhifadhi\Roster\Model\LiveRailGroup;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;

/**
 * WHERE EVERYBODY IS, FED TO THE ATLAS.
 *
 * THIS MODULE DRAWS NOTHING. The plate, its imagery, its controls and its
 * key are the atlas's; the ground, the boundary, the zones and the posts are
 * the AREA's. What the roster contributes is one marker layer per presence
 * state and the legend group over them — and it contributes them by handing
 * the atlas features, never by rendering a map.
 *
 * THE POSITIONS ARE THE AREA'S TOO, read through
 * {@see \Uhifadhi\Contracts\Area\LivePositionsInterface}. This module stores
 * no position and derives no state: a marker's colour is the state the AREA
 * already derived for that watch, so a ranger cannot read "verified" on the
 * map and unverified on the day board.
 *
 * A COLOUR IS A TOKEN NAME, NEVER A VALUE. A module declares no colour, and
 * that has no exception for a swatch that happens to be read by JavaScript:
 * the layer publishes `var(--plate-ok)` and the plate resolves it against
 * its own container. The plate palette is fixed on imagery — dark in both
 * themes — which is why these are the plate's tokens and not the shell's
 * semantic ones.
 *
 * STALENESS IS THE ANSWER'S. {@see LivePresence::isStale()} decides what is
 * old, from the area's own ping interval; a threshold of this module's would
 * be a second opinion about a number the area already publishes.
 */
final readonly class RosterLiveService
{
    /** The key's heading for everything this module puts on the plate. */
    public const string GROUP = 'Roster presence states';

    /** The layer ids, namespaced with this module's own word. */
    public const string LAYER_PREFIX = 'roster.presence.';

    /** The one layer that is not a state: a fix older than the area allows. */
    public const string STALE_LAYER = self::LAYER_PREFIX.'stale';

    public function __construct(
        private AreaPlateService $plates,
        private ZoneSetService $zones,
        private StationRepository $stations,
        private PostingRepository $postings,
    ) {
    }

    /**
     * THE AREA'S PLATE, WITH THIS MODULE'S MARKERS ON IT.
     *
     * The base is the area's own stations plate — the same ground, the same
     * rings and the same posts a reader saw on the Stations tab, so nobody
     * has to learn a second map. Only the presence layers are ours.
     */
    public function plate(AreaOfInterest $area, LivePresence $live): AtlasMap
    {
        $view = $this->zones->view($area);

        $hues = [];
        foreach ($view->rows as $zone) {
            $hues[$zone->name] = $zone->hue;
        }

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $zone = $post->getZone()?->getName();
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
                'zone' => $zone,
                'hue' => null === $zone ? null : ($hues[$zone] ?? null),
            ];
        }

        $map = $this->plates->stationsPlate($area, $view->rows, $posts);

        // ONE LAYER PER STATE, and a sixth for the fixes that are too old.
        // Separate layers rather than one coloured by a property, because
        // the key's rows are what a reader switches — "show me only the
        // unverified" is the question this plate is opened with.
        foreach (self::states() as $state => [$label, $swatch]) {
            $features = [];
            foreach ($live->positions as $position) {
                if ($position->state->value === $state && !$live->isStale($position)) {
                    $features[] = self::marker($position, $live);
                }
            }

            $map->addLayer(new GeoJsonLayer(
                id: self::LAYER_PREFIX.$state,
                label: $label,
                features: self::collection($features),
                swatch: $swatch,
                shape: LayerShape::Point,
                visible: [] !== $features,
                count: \count($features),
                group: self::GROUP,
                tooltip: 'label',
            ));
        }

        $stale = [];
        foreach ($live->positions as $position) {
            if ($live->isStale($position)) {
                $stale[] = self::marker($position, $live);
            }
        }

        // A STALE FIX IS DRAWN, NEVER DROPPED. Where somebody was an hour
        // ago is a fact worth having on the ground; what it must not do is
        // wear the colour of a fresh one and be read as where they are.
        $map->addLayer(new GeoJsonLayer(
            id: self::STALE_LAYER,
            label: \sprintf('ping older than the interval · %d min', $live->pingIntervalMinutes),
            features: self::collection($stale),
            swatch: 'var(--plate-fail)',
            shape: LayerShape::Point,
            visible: [] !== $stale,
            count: \count($stale),
            group: self::GROUP,
            tooltip: 'label',
        ));

        return $map;
    }

    /**
     * THE STATES A MARKER MAY WEAR, in the key's own order, each with the
     * PLATE token it is drawn in.
     *
     * VERIFIED IS ABSENT FROM NOTHING HERE, but it will be empty in every
     * installation until the core grows a writer for a post's catchment —
     * the same gap the configure page flags. The row still ships, because a
     * key that hid a state would make its absence look like a decision.
     *
     * @return array<string, array{string, string}>
     */
    public static function states(): array
    {
        return [
            DayState::AtPostVerified->value => ['at post · verified', 'var(--plate-ok)'],
            DayState::AtPostUnverified->value => ['at post · unverified', 'var(--plate-warn)'],
            DayState::Special->value => ['special assignment', 'var(--plate-acc)'],
            DayState::WorkingElsewhere->value => ['working elsewhere', 'var(--plate-acc)'],
            DayState::NotWorking->value => ['not working', 'var(--plate-dim)'],
        ];
    }

    /**
     * ONE PERSON, ONE POINT. Never a trail: a line of where somebody has
     * been is a patrol track, and drawing one here would answer a question
     * this tab is not asking with data the area publishes for another.
     *
     * @return array<string, mixed>
     */
    private static function marker(LivePosition $position, LivePresence $live): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$position->longitude, $position->latitude]],
            'properties' => [
                'id' => $position->personUuid,
                'label' => \sprintf(
                    '%s · %s · %s',
                    $position->personName,
                    $position->stationName ?? 'no post',
                    self::age($position->ageSeconds($live->asOf)),
                ),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<string, mixed>
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * THE RAIL BESIDE THE PLATE — everybody, grouped the way the design
     * reads them, with "no position" last.
     *
     * THE RAIL IS THE ROSTER'S LIST AND THE MAP IS THE AREA'S ANSWER, and
     * they are deliberately not the same set: somebody at their post whose
     * phone has said nothing is on this list and not on that map. Inventing
     * a marker at the post's own point would turn a claim into proof, which
     * is the one thing this module exists to avoid.
     *
     * @param list<PostPresence> $posts
     *
     * @return list<LiveRailGroup>
     */
    public function rail(LivePresence $live, array $posts): array
    {
        $fixes = [];
        foreach ($live->positions as $position) {
            $fixes[$position->personUuid] = $position;
        }

        $groups = [
            DayState::AtPostVerified->value => [],
            DayState::AtPostUnverified->value => [],
            'away' => [],
            'none' => [],
        ];

        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $fix = $fixes[$person->personUuid] ?? null;
                $key = match (true) {
                    null === $fix => 'none',
                    DayState::AtPostVerified === $fix->state => DayState::AtPostVerified->value,
                    DayState::AtPostUnverified === $fix->state => DayState::AtPostUnverified->value,
                    default => 'away',
                };

                $groups[$key][] = [
                    'person' => $person,
                    'post' => $post,
                    'fix' => $fix,
                    'age' => null === $fix ? null : self::age($fix->ageSeconds($live->asOf)),
                    'stale' => null !== $fix && $live->isStale($fix),
                ];
            }
        }

        $labels = [
            DayState::AtPostVerified->value => ['at post · verified', 'st-ok'],
            DayState::AtPostUnverified->value => ['at post · unverified', 'st-warn'],
            'away' => ['not at a post', ''],
            'none' => ['no position', 'st-fail'],
        ];

        $rail = [];
        foreach ($groups as $key => $rows) {
            [$label, $tone] = $labels[$key];
            $rail[] = new LiveRailGroup($label, $tone, $rows);
        }

        return $rail;
    }

    /**
     * THE FIVE FIGURES ABOVE THE PLATE, counted over the same answer the
     * plate and the rail are drawn from.
     *
     * @param list<PostPresence> $posts
     */
    public function figures(LivePresence $live, array $posts): LiveFigures
    {
        $expected = 0;
        $reporting = 0;
        foreach ($posts as $post) {
            $expected += \count($post->rostered);

            if (PostState::Reporting === $post->state) {
                ++$reporting;
            }
        }

        $verified = 0;
        $away = 0;
        $oldest = null;
        foreach ($live->positions as $position) {
            if (DayState::AtPostVerified === $position->state) {
                ++$verified;
            } elseif (DayState::AtPostUnverified !== $position->state) {
                ++$away;
            }

            $age = $position->ageSeconds($live->asOf);
            $oldest = null === $oldest ? $position : ($age > $oldest->ageSeconds($live->asOf) ? $position : $oldest);
        }

        return new LiveFigures(
            positions: \count($live->positions),
            expected: $expected,
            stale: $live->staleCount(),
            withoutAFix: max(0, $expected - \count($live->positions)),
            verified: $verified,
            awayFromAPost: $away,
            oldestAge: null === $oldest ? null : self::age($oldest->ageSeconds($live->asOf)),
            oldestName: $oldest?->personName,
            oldestStation: $oldest?->stationName,
            pingIntervalMinutes: $live->pingIntervalMinutes,
            postsReporting: $reporting,
            postsOnTheBooks: \count($posts),
        );
    }

    /**
     * HOW OLD A FIX IS, in the words a person uses. Minutes up to an hour
     * and hours after it — "247 min" is a number somebody has to divide.
     */
    public static function age(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        if ($minutes < 60) {
            return \sprintf('%d min', $minutes);
        }

        return \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Whether anybody on the books is rostered at all, which is the
     * difference between "nobody is reporting" and "nobody is due".
     *
     * @param list<PostPresence> $posts
     */
    public static function anybodyDue(array $posts): bool
    {
        foreach ($posts as $post) {
            if ([] !== $post->rostered) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RosteredPerson> */
    public static function nobody(): array
    {
        return [];
    }
}
