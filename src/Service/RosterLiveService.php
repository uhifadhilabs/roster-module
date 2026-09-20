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
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Roster\Model\LiveFigures;
use Uhifadhi\Roster\Model\LiveRailGroup;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RailList;
use Uhifadhi\Roster\Model\RailRow;
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
    public function plate(AreaOfInterest $area, LivePresence $live, int $withoutPosition = 0): AtlasMap
    {
        $view = $this->zones->view($area);

        // THE POSTS AS THE AREA'S PLATE ASKS FOR THEM, and not one field
        // more. A ZONE'S NAME IS A NAME; ITS COLOUR IS THE AREA'S, and
        // this module neither reads it nor passes it on. The zone rows go
        // through untouched and the area hues them — the only arrangement
        // in which the same zone is the same colour on every plate in the
        // product, and the only one that does not have to be edited every
        // time that palette changes.
        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
                'zone' => $post->getZone()?->getName(),
            ];
        }

        $map = $this->plates->stationsPlate($area, $view->rows, $posts);

        // WHERE PEOPLE ARE, DRAWN BY THE ATLAS. One call adds the live
        // layer AND the key that must come with it — live, stale, and the
        // people this read had no fix for at all, who are on no layer and
        // would otherwise go unmentioned.
        //
        // THIS MODULE NAMES NO COLOUR, NO SIZE AND NO TYPEFACE. The mark is
        // the house's `.livedot` component; staleness is the ANSWER's, from
        // LivePresence::isStale at two ping intervals, never a threshold of
        // ours. All the roster contributes is the positions and the count
        // of the people missing from them.
        $map->livePositions($live, $withoutPosition);

        return $map;
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
     * THE STATIONS LIST — one row per post the AREA registers, not per post
     * this module keeps a watch on.
     *
     * THE POSTS LEFT THE LEGEND TO GET HERE, and that is the ruling worth
     * restating: a legend is a KEY TO WHAT IS DRAWN, and "which post has
     * how many people on it right now" is not a key, it is the answer. A
     * key that carried answers would grow with the park.
     *
     * THE ONES WITH A WATCH COME FIRST. The rest are still listed, quiet,
     * because a post this module ignores is still a post on the plate and
     * a reader who clicks it should be centred on it.
     *
     * @param list<PostPresence> $posts the posts on this module's books
     */
    public function stations(AreaOfInterest $area, LivePresence $live, array $posts): RailList
    {
        $onTheBooks = [];
        foreach ($posts as $post) {
            $onTheBooks[$post->stationUuid] = $post;
        }

        $liveAt = [];
        $staleAt = [];
        foreach ($live->positions as $position) {
            $at = $position->stationUuid;
            if (null === $at) {
                continue;
            }

            if ($live->isStale($position)) {
                $staleAt[$at] = ($staleAt[$at] ?? 0) + 1;

                continue;
            }

            $liveAt[$at] = ($liveAt[$at] ?? 0) + 1;
        }

        $watched = [];
        $quiet = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $uuid = (string) $station->getUuidString();
            $posted = $this->postings->countStandingByStation($station);
            $stale = $staleAt[$uuid] ?? 0;

            $row = new RailRow(
                // A POST WITH NO POINT CANNOT BE CENTRED ON, so its row is
                // not a link. The area gazettes a post before it surveys
                // one, and a dead link is worse than an inert row.
                centre: null === $station->getPoint() ? null : $uuid,
                name: (string) $station->getName(),
                detail: self::detail([$station->getCode(), self::people($posted)]),
                live: $liveAt[$uuid] ?? 0,
                wrong: $stale > 0 ? \sprintf('%d stale', $stale) : null,
                quiet: !isset($onTheBooks[$uuid]),
            );

            if (isset($onTheBooks[$uuid])) {
                $watched[] = $row;
            } else {
                $quiet[] = $row;
            }
        }

        $sections = [];
        if ([] !== $watched) {
            $sections[] = ['label' => \sprintf('with a watch · %d', \count($watched)), 'rows' => $watched];
        }

        if ([] !== $quiet) {
            $sections[] = ['label' => \sprintf('no watch in this module · %d', \count($quiet)), 'rows' => $quiet];
        }

        return new RailList('stations', 'Stations', $sections);
    }

    /**
     * THE ZONES LIST — one row per zone, with the swatch the plate draws it
     * in.
     *
     * THE SWATCH IS A CATEGORY POSITION AND NEVER A COLOUR. A zone's place
     * in the set picks the house's nth category token; this module does not
     * read the zone's own colour field and does not name a value. The same
     * position gives the same token on the plate and in this list, which is
     * the only reason the two agree.
     *
     * A ZONE'S LIVE COUNT IS ITS POSTS' — the positions claimed at a post
     * that stands inside it. A fix with no post claimed belongs to nobody's
     * zone, and guessing one from a coordinate would be this module doing
     * geography the area already does.
     */
    public function zones(AreaOfInterest $area, LivePresence $live): RailList
    {
        $liveAt = [];
        foreach ($live->positions as $position) {
            if (null !== $position->stationUuid && !$live->isStale($position)) {
                $liveAt[$position->stationUuid] = ($liveAt[$position->stationUuid] ?? 0) + 1;
            }
        }

        $postedIn = [];
        $liveIn = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $zone = $station->getZone()?->getName();
            if (null === $zone) {
                continue;
            }

            $postedIn[$zone] = ($postedIn[$zone] ?? 0) + $this->postings->countStandingByStation($station);
            $liveIn[$zone] = ($liveIn[$zone] ?? 0) + ($liveAt[(string) $station->getUuidString()] ?? 0);
        }

        $staffed = [];
        $empty = [];
        foreach ($this->zones->view($area)->rows as $position => $zone) {
            $posted = $postedIn[$zone->name] ?? 0;

            $row = new RailRow(
                centre: $zone->uuid,
                name: $zone->name,
                detail: self::detail([self::km2($zone->km2), self::people($posted)]),
                live: $liveIn[$zone->name] ?? 0,
                category: ($position % self::CATEGORIES) + 1,
                quiet: 0 === $posted,
            );

            if ($posted > 0) {
                $staffed[] = $row;
            } else {
                $empty[] = $row;
            }
        }

        $sections = [];
        if ([] !== $staffed) {
            $sections[] = ['label' => \sprintf('with somebody posted · %d', \count($staffed)), 'rows' => $staffed];
        }

        if ([] !== $empty) {
            $sections[] = ['label' => \sprintf('nobody posted · %d', \count($empty)), 'rows' => $empty];
        }

        return new RailList('zones', 'Zones', $sections);
    }

    /** The house ships nine category tokens; a longer set wraps round them. */
    private const int CATEGORIES = 9;

    /**
     * A row's second line: the facts it has, in order, dots between.
     *
     * @param list<string|null> $parts
     */
    private static function detail(array $parts): string
    {
        return implode(' · ', array_values(array_filter(
            $parts,
            static fn (?string $part): bool => null !== $part && '' !== $part,
        )));
    }

    private static function people(int $posted): string
    {
        return 0 === $posted ? 'nobody posted' : \sprintf('%d posted', $posted);
    }

    private static function km2(int $km2): string
    {
        return \sprintf('%s km²', number_format($km2));
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
