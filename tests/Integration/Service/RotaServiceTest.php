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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\RotaCellKind;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE ROTA'S GEOMETRY — PEOPLE DOWN, GROUPED BY POST, DAYS ACROSS.
 *
 * The design is the spec and it changed: the grid was posts down over a
 * week, and it is now one row per PERSON under a heading per post, across a
 * fortnight. The difference is not cosmetic — a post-row cannot show "who is
 * working too many nights", which is the question a planner opens this tab
 * to answer.
 */
final class RotaServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private Station $outpost;
    private User $ada;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->outpost = $this->aStation($this->area, 'west outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada');
        $this->ben = $this->aPerson('ben@example.test', 'Ben');
        $this->em->flush();

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate)->expect(['day', 'night']);
        $watches->addToRoster($this->outpost)->expect(['day']);

        $ring = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', 'night', Cycle::OFF]),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 1, 'night' => 1],
            42,
        )->standAt($this->gate);
        $this->em->persist($ring);
        $this->em->persist(new RotationPoolMember($ring, $this->ada, 0));
        $this->em->persist(new RotationPoolMember($ring, $this->ben, 1));
        $this->em->flush();
    }

    private function rota(): RotaService
    {
        $rota = $this->service(RotaService::class);
        self::assertInstanceOf(RotaService::class, $rota);

        return $rota;
    }

    private function aDuty(User $person, Station $at, string $shiftKey, string $day): Duty
    {
        $duty = new Duty($this->area, $at, $person, $shiftKey, new \DateTimeImmutable($day));
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    /** A FORTNIGHT, not a week: fourteen columns, monday-anchored. */
    public function testTheGridIsAFortnightAnchoredOnAMonday(): void
    {
        $days = $this->rota()->days(RotaService::start(new \DateTimeImmutable('2026-09-17')));

        self::assertCount(14, $days);
        self::assertSame('2026-09-14', $days[0]->format('Y-m-d'));
        self::assertSame('2026-09-27', $days[13]->format('Y-m-d'));
        self::assertSame('Mon', $days[0]->format('D'));
    }

    /**
     * PEOPLE DOWN, GROUPED BY POST. One group per post on the books, each
     * carrying the people its ring draws from — which is the row a planner
     * reads "who is working too many nights" off.
     */
    public function testRowsArePeopleGroupedByPost(): void
    {
        $groups = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'));

        self::assertCount(2, $groups, 'One group per post on the books.');
        self::assertSame('north gate post', $groups[0]->stationName);
        self::assertSame(['Ada Example', 'Ben Example'], array_map(static fn ($row): string => $row->personName, $groups[0]->rows));

        // A post with a watch and no ring has no people to draw: an honest
        // empty group, not a missing one.
        self::assertSame('west outpost', $groups[1]->stationName);
        self::assertSame([], $groups[1]->rows);
    }

    /** The group heading states what the post's watch asks for. */
    public function testAGroupStatesWhatThePostAsksFor(): void
    {
        $groups = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame('ST-01', $groups[0]->stationCode);
        self::assertSame('day 1 · night 1', $groups[0]->asks);
    }

    /** Every day of the fortnight has a cell, and a day with no duty is OFF. */
    public function testEveryDayHasACellAndNoDutyIsOff(): void
    {
        $this->aDuty($this->ada, $this->gate, 'day', '2026-09-14');

        $row = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'))[0]->rows[0];

        self::assertCount(14, $row->cells);
        self::assertSame(RotaCellKind::Day, $row->cells['2026-09-14']->kind);
        self::assertSame(RotaCellKind::Off, $row->cells['2026-09-15']->kind);
    }

    /** A night watch reads as a night, and carries its window. */
    public function testANightWatchReadsAsANight(): void
    {
        $this->aDuty($this->ben, $this->gate, 'night', '2026-09-16');

        $rows = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'))[0]->rows;
        $cell = $rows[1]->cells['2026-09-16'];

        self::assertSame(RotaCellKind::Night, $cell->kind);
        self::assertSame('night', $cell->label);
        self::assertSame('18:00–06:00', $cell->window);
    }

    /**
     * THE TWO TRADED CELLS ARE MARKED. swapA is the watch being given up,
     * swapB the one being taken — and until the handset accepts, BOTH
     * duties still stand where the rotation put them. The marks say a trade
     * is in flight; they do not move anybody.
     */
    public function testAnOpenSwapMarksBothCells(): void
    {
        $given = $this->aDuty($this->ada, $this->gate, 'night', '2026-09-19');

        $swaps = $this->service(SwapService::class);
        self::assertInstanceOf(SwapService::class, $swaps);
        $swaps->offer($given, $this->ben, $this->ada);

        $rows = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'))[0]->rows;

        self::assertSame(RotaCellKind::SwapGiven, $rows[0]->cells['2026-09-19']->kind, 'Ada is giving the watch up.');
        self::assertSame(RotaCellKind::SwapTaken, $rows[1]->cells['2026-09-19']->kind, 'Ben is being asked to take it.');
        // And the watch is still Ada's until Ben accepts.
        self::assertSame($this->ada->getId(), $given->getPerson()->getId());
    }

    /** An ANSWERED swap marks nothing: the grid shows the roster as it is. */
    public function testAnAnsweredSwapMarksNothing(): void
    {
        $given = $this->aDuty($this->ada, $this->gate, 'night', '2026-09-19');

        $swaps = $this->service(SwapService::class);
        self::assertInstanceOf(SwapService::class, $swaps);
        $swaps->decline($swaps->offer($given, $this->ben, $this->ada), new \DateTimeImmutable());

        $rows = $this->rota()->groups($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'))[0]->rows;

        self::assertSame(RotaCellKind::Night, $rows[0]->cells['2026-09-19']->kind);
        self::assertSame(RotaCellKind::Off, $rows[1]->cells['2026-09-19']->kind);
    }

    /**
     * ONLY TODAY'S COLUMN CARRIES A CHECK-IN STATE. Every other column is
     * what the rotation SAYS, not what happened — and a grid that painted a
     * state on a future cell would be stating a plan as a fact.
     */
    public function testOnlyTodaysColumnCarriesAState(): void
    {
        $today = new \DateTimeImmutable('today');
        $this->aDuty($this->ada, $this->gate, 'day', $today->format('Y-m-d'));
        $this->aDuty($this->ada, $this->gate, 'day', $today->modify('+1 day')->format('Y-m-d'));

        $row = $this->rota()->groups($this->area, RotaService::start($today), RotaService::start($today)->modify('+13 days'))[0]->rows[0];

        self::assertTrue($row->cells[$today->format('Y-m-d')]->isToday);
        self::assertFalse($row->cells[$today->modify('+1 day')->format('Y-m-d')]->isToday);
    }

    /**
     * THE FIVE FIGURES ABOVE THE GRID, each computed from the same fortnight
     * the grid draws — a strip that measured a different window would be a
     * strip nobody could check against the picture under it.
     */
    public function testTheFiguresMeasureTheSameFortnightTheGridDraws(): void
    {
        $this->aDuty($this->ada, $this->gate, 'day', '2026-09-14');
        $this->aDuty($this->ada, $this->gate, 'night', '2026-09-15');
        $this->aDuty($this->ben, $this->gate, 'night', '2026-09-16');
        // Outside the window: must not be counted.
        $this->aDuty($this->ben, $this->gate, 'night', '2026-10-20');

        $figures = $this->rota()->figures($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(3, $figures->personWatches, 'Three inside the fortnight; the October one is outside it.');
        // THE HEAVIEST IS PER PERSON, not a total: Ada stands one night and
        // Ben one, so the heaviest anybody carries is one. A figure that
        // added them up would say the park's worst-loaded ranger has two.
        self::assertSame(1, $figures->heaviestNights);
        self::assertSame('Ada Example', $figures->heaviestName);
    }

    /** A post with no ring contributes no people and no figures. */
    public function testAPostWithNoRingContributesNothingToTheFigures(): void
    {
        $figures = $this->rota()->figures($this->area, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(0, $figures->personWatches);
        self::assertSame(0, $figures->swapsPending);
    }
}
