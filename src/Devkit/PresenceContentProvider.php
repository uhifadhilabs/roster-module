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

namespace Uhifadhi\Roster\Devkit;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE PROOF BEHIND THE PLAN — the check-ins and the positions that turn a
 * month of rostered days into a month somebody actually worked.
 *
 * IT DERIVES NOTHING AND WRITES THROUGH THE HANDSET'S OWN DOOR. Presence
 * is the AREA's, ruled, and a seeder that wrote "verified" into a column
 * would be computing it — the one thing this module may not do. Worse, it
 * would go on agreeing with itself long after the real derivation had
 * moved, so the demo would be the last place a change in the rules showed
 * up. So this claims and pings exactly as a phone does, through
 * {@see CheckInService}, and every reading on every page is then derived
 * from those rows by the area at the moment somebody asks.
 *
 * IT REPORTS ONLY FROM THE PAST. A check-in dated next week is not demo
 * content; it is a board stating a plan as a fact. Today is the far end,
 * and today's late watches are deliberately left open, because a live
 * plate with nothing in flight on it is not a live plate.
 *
 * IT PRODUCES THE READINGS THE SCREENS DRAW — at post, out on an escort,
 * unfit, a special assignment, a claim the positions do not bear out, a
 * watch nobody closed, and days holding more than one watch. A demo where
 * everybody is quietly at post exercises one branch of Today and ships
 * the others broken.
 *
 * ONE READING IT CANNOT PRODUCE IS `at_post_verified`, and that is not a
 * gap in this seeder. A claim is verified when a position falls inside the
 * post's catchment, and the catchment the area verifies against is
 * `Station::catchmentM` — a column the product ships, reads and exposes to
 * the handset, but that NOTHING anywhere writes: there is no service verb
 * for it, no form field, and the area's own `describe()` deliberately
 * enumerates its facts without it. Until the core grows a writer, every
 * at-post claim in every installation reads "unverified — the post has no
 * ring", and so does this demo. Seeding round it by writing the column
 * from here would hide a live bug behind a pretty screenshot.
 */
final readonly class PresenceContentProvider implements ContentProviderInterface
{
    /** How far a "wandered off" fix is placed from the post, in metres. */
    private const int STRAY_METRES = 7_400;

    /** Metres per degree of latitude — near enough anywhere for a demo. */
    private const float METRES_PER_DEGREE = 111_320.0;

    public function __construct(
        private AreaOfInterestRepository $areas,
        private DutyRepository $duties,
        private ShiftRepository $shifts,
        private ?CheckInService $checkIns,
        private CheckInStatusService $statuses,
    ) {
    }

    public function key(): string
    {
        return 'roster-presence';
    }

    public function label(): string
    {
        return 'Roster presence';
    }

    public function description(): string
    {
        return 'Check-ins and positions against the demo duties, so the board, Today and the live plate read like a worked month.';
    }

    /** There is nothing to report from until somebody has been rostered. */
    public function dependsOn(): array
    {
        return ['roster'];
    }

    public function load(): void
    {
        if (null === $this->checkIns) {
            // NO FIELD API, NO REPORTED PRESENCE. The area registers the
            // handset's door only where ApiPlatform and Security both are;
            // without it nothing in the installation can record a check-in,
            // so a demo that manufactured some would be showing rows no
            // running system could ever produce.
            return;
        }

        foreach ($this->areas->findAll() as $area) {
            $this->loadArea($area);
        }
    }

    private function loadArea(AreaOfInterest $area): void
    {
        $from = new \DateTimeImmutable('first day of this month')->setTime(0, 0);
        $today = new \DateTimeImmutable('today');

        $duties = $this->duties->findByAreaBetween($area, $from, $today);
        if ([] === $duties) {
            return;
        }

        $statuses = $this->statusesOf($area);
        if (!isset($statuses[CheckInStatusKind::AtPost->value])) {
            return;
        }

        $windows = $this->shifts->windowsFor($area);

        foreach ($duties as $duty) {
            $window = $windows[$duty->getShiftKey()] ?? null;
            if (null === $window) {
                continue;
            }

            $this->workTheWatch($area, $duty, $window, $statuses, $today);
        }
    }

    /**
     * ONE ROSTERED WATCH, WORKED. The draw is a function of the duty, so
     * the same watch tells the same story on every run.
     *
     * @param array<string, CheckInStatus> $statuses
     */
    private function workTheWatch(AreaOfInterest $area, Duty $duty, ShiftWindow $window, array $statuses, \DateTimeImmutable $today): void
    {
        $draw = DemoDraw::of('watch', (string) $duty->getUuid());
        $day = $duty->getOnDay();
        $isToday = $day->format('Y-m-d') === $today->format('Y-m-d');

        // A FEW WATCHES ARE SIMPLY NOT REPORTED, and that is the "no
        // check-in" a board draws against somebody who was due. A demo
        // where every rostered person checked in never shows it.
        if ($draw->oneIn(14)) {
            return;
        }

        $startedAt = $day->setTime(0, 0)->modify(\sprintf('+%d minutes', $window->startsAtMinuteOfDay() + $draw->between(0, 25)));
        $endsAt = $startedAt->modify(\sprintf('+%d minutes', $window->lengthMinutes()));

        // THE FIRST WATCH OF THE DAY. Most are at post; the rest are the
        // reasons a park actually records.
        $kind = match (true) {
            $draw->oneIn(11) => CheckInStatusKind::WorkingElsewhere,
            $draw->oneIn(17) => CheckInStatusKind::NotWorking,
            $draw->oneIn(19) => CheckInStatusKind::Special,
            default => CheckInStatusKind::AtPost,
        };

        // A WATCH NOBODY CLOSED. On a past day the area derives it once
        // the rostered end has gone by, which is what makes it a fact
        // rather than a failure. Today's watches that have not ended yet
        // are simply still running, which is a different thing.
        $stillOut = $isToday ? $endsAt > new \DateTimeImmutable() : $draw->oneIn(9);

        $this->claim(
            $area,
            $duty,
            \sprintf('demo-%s-1', $duty->getUuid()),
            $statuses,
            $kind,
            $startedAt,
            $stillOut ? null : $endsAt,
            $draw,
        );

        // A DAY HOLDS ANY NUMBER OF WATCHES (ruled). About one day in five
        // is a morning at the post and an afternoon somewhere else, which
        // is the case a demo of single intervals never produces and every
        // surface now has to draw as two rows and a total.
        if (!$stillOut && $draw->then('second')->oneIn(5)) {
            $secondDraw = $draw->then('second-watch');
            $resumedAt = $endsAt->modify(\sprintf('+%d minutes', $secondDraw->between(45, 150)));

            if ($resumedAt < new \DateTimeImmutable() && $resumedAt->format('Y-m-d') === $day->format('Y-m-d')) {
                $this->claim(
                    $area,
                    $duty,
                    \sprintf('demo-%s-2', $duty->getUuid()),
                    $statuses,
                    $secondDraw->oneOf([CheckInStatusKind::WorkingElsewhere, CheckInStatusKind::AtPost, CheckInStatusKind::Special]),
                    $resumedAt,
                    $resumedAt->modify(\sprintf('+%d minutes', $secondDraw->between(90, 240))),
                    $secondDraw,
                );
            }
        }
    }

    /**
     * ONE CLAIM AND THE POSITIONS BEHIND IT, sent the way a handset sends
     * them: the claim first, then the pings it collected while it was out
     * of signal. Both carry the client's own reference, which is what
     * makes a second run of the seeder the same day rather than a second
     * one — the area answers a repeated reference with "duplicate" and
     * writes nothing.
     *
     * @param array<string, CheckInStatus> $statuses
     */
    private function claim(
        AreaOfInterest $area,
        Duty $duty,
        string $ref,
        array $statuses,
        CheckInStatusKind $kind,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        DemoDraw $draw,
    ): void {
        $status = $statuses[$kind->value] ?? null;
        if (null === $status) {
            return;
        }

        $body = [
            'clientRef' => $ref,
            'localDate' => $duty->getOnDay()->format('Y-m-d'),
            'status' => $status->getKey(),
            'occurredAt' => $startedAt->format(\DATE_ATOM),
            'deviceId' => \sprintf('demo-handset-%02d', $draw->between(1, 24)),
            'appVersion' => '1.4.0',
        ];

        if ($kind->takesStation()) {
            $body['stationUuid'] = $duty->getStation()->getUuidString();
        }

        if ($kind->takesNote()) {
            $body['note'] = $draw->oneOf([
                'Escorting the vet team to the north boundary',
                'Court appearance in town',
                'Fever, reported to the warden',
                'Standing in on the ranger course',
            ]);
        }

        // A CLAIM WITH NO FIX AT ALL happens, and it is the never-block
        // rule seen from the other end: the watch started without a
        // position and the area says so rather than refusing the claim.
        $blind = $kind->takesStation() && $draw->then('blind')->oneIn(13);
        $stray = $draw->then('stray')->oneIn(9);

        if (!$blind) {
            $fix = $this->fixNear($duty, $stray);
            if (null !== $fix) {
                $body['lat'] = $fix[1];
                $body['lon'] = $fix[0];
                $body['accuracyM'] = (float) $draw->between(6, 40);
                $body['positionAt'] = $startedAt->format(\DATE_ATOM);
            }
        }

        [$checkIn, $repeat] = $this->door()->claim($area, $duty->getPerson(), $body);

        if (!$repeat && null !== $endedAt) {
            $this->door()->amend($checkIn, [
                'endedAt' => $endedAt->format(\DATE_ATOM),
                'handoverNote' => $draw->then('handover')->oneIn(4) ? 'Radio handed over, nothing outstanding' : null,
            ]);
        }

        if ($blind) {
            return;
        }

        $this->ping($area, $duty, $ref, $startedAt, $endedAt, $stray, $draw);
    }

    /**
     * THE POSITIONS A WATCH SENT, spread across the time it ran — which is
     * what the live plate draws and what the silence thresholds are
     * measured against.
     */
    private function ping(
        AreaOfInterest $area,
        Duty $duty,
        string $ref,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        bool $stray,
        DemoDraw $draw,
    ): void {
        $fix = $this->fixNear($duty, $stray);
        if (null === $fix) {
            return;
        }

        $until = $endedAt ?? new \DateTimeImmutable();
        $minutes = max(0, (int) floor(($until->getTimestamp() - $startedAt->getTimestamp()) / 60));
        $count = $draw->then('pings')->between(2, 5);
        $step = (int) floor($minutes / ($count + 1));

        if ($step < 1) {
            return;
        }

        $rows = [];
        for ($n = 1; $n <= $count; ++$n) {
            $at = $startedAt->modify(\sprintf('+%d minutes', $step * $n));
            if ($at > new \DateTimeImmutable()) {
                break;
            }

            // A DRIFT OF A FEW HUNDRED METRES between fixes, so the plate
            // draws a patrol standing a watch rather than a pin.
            $wander = $draw->then('wander'.$n)->between(-400, 400) / self::METRES_PER_DEGREE;

            $rows[] = [
                'clientRef' => \sprintf('%s-p%d', $ref, $n),
                'checkinRef' => $ref,
                'recordedAt' => $at->format(\DATE_ATOM),
                'lat' => $fix[1] + $wander,
                'lon' => $fix[0] + $wander,
                'accuracyM' => (float) $draw->then('acc'.$n)->between(5, 45),
                'batteryPct' => max(8, 100 - ($n * $draw->then('batt')->between(4, 11))),
                'source' => 'gps',
            ];
        }

        if ([] === $rows) {
            return;
        }

        $this->door()->ping($area, $duty->getPerson(), ['positions' => $rows]);
    }

    /**
     * A POSITION AT THE POST, or well away from it where the watch is one
     * of the ones that wandered. Null where the post has no point at all,
     * which is a post nothing can be measured against.
     *
     * @return array{0: float, 1: float}|null lon, lat
     */
    private function fixNear(Duty $duty, bool $stray): ?array
    {
        $point = $duty->getStation()->getPoint();
        if (null === $point) {
            return null;
        }

        /** @var array{coordinates?: array{0?: float|int, 1?: float|int}} $decoded */
        $decoded = json_decode($point, true, 8, \JSON_THROW_ON_ERROR);
        $lon = $decoded['coordinates'][0] ?? null;
        $lat = $decoded['coordinates'][1] ?? null;

        if (!is_numeric($lon) || !is_numeric($lat)) {
            return null;
        }

        $offset = $stray ? self::STRAY_METRES / self::METRES_PER_DEGREE : 0.0;

        return [(float) $lon, (float) $lat + $offset];
    }

    /**
     * THE HANDSET'S DOOR, once {@see load()} has established there is one.
     * Reached through a verb rather than the property so that every write
     * below is plainly downstream of that one check, instead of each call
     * site re-asking a question already answered.
     */
    private function door(): CheckInService
    {
        return $this->checkIns ?? throw new \LogicException('The demo presence seeder reached the handset API after establishing there was none.');
    }

    /**
     * THE AREA'S OWN ANSWERS, one per kind — asked for through the area's
     * service so an area that has never been asked gets the defaults
     * seeded by the code that owns them rather than by this.
     *
     * @return array<string, CheckInStatus>
     */
    private function statusesOf(AreaOfInterest $area): array
    {
        $byKind = [];
        foreach ($this->statuses->offeredBy($area) as $status) {
            $kind = $status->getKind();
            if (!isset($byKind[$kind->value])) {
                $byKind[$kind->value] = $status;
            }
        }

        return $byKind;
    }
}
