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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\PlannedDuty;
use Uhifadhi\Roster\Model\RotationPlan;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Service\CyclePlanner;

/**
 * The rotation algebra, with no database and no people in it.
 *
 * THE WORKED EXAMPLE THROUGHOUT is the one the design is drawn in: a gate
 * running a five-day ring of two days, two nights and one off, out of a pool
 * of five, anchored on monday. It is the case the whole model exists to make
 * true — five people, staffed for ever, nobody filling in a rota.
 */
final class CyclePlannerTest extends TestCase
{
    /** @return array<string, ShiftWindow> */
    private function windows(): array
    {
        return [
            'day' => ShiftWindow::of('day', '06:00', '18:00'),
            'night' => ShiftWindow::of('night', '18:00', '06:00'),
            'office' => ShiftWindow::of('office', '07:30', '16:30'),
        ];
    }

    private function gate(RestRule $rule = RestRule::None, int $poolSize = 5): RotationPlan
    {
        return new RotationPlan(
            Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]),
            $poolSize,
            // Monday 14 September 2026 — the design's own "day 1 is mon 14 sep".
            new \DateTimeImmutable('2026-09-14'),
            [],
            $rule,
            $this->windows(),
        );
    }

    /**
     * @param list<PlannedDuty> $planned
     *
     * @return array<string, int> shift key => how many people, for one day
     */
    private function countsOn(array $planned, string $day): array
    {
        $counts = [];
        foreach ($planned as $duty) {
            if ($duty->onDay->format('Y-m-d') === $day) {
                $counts[$duty->shiftKey] = ($counts[$duty->shiftKey] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * THE CLAIM THE WHOLE MODEL RESTS ON: a five-day ring and a pool of five
     * staffs the post EVERY day — two on the day watch, two on the night, one
     * standing down — without anybody writing a rota.
     */
    public function testAFiveDayRingAndAPoolOfFiveStaffTheGateEveryDay(): void
    {
        $planned = new CyclePlanner()->plan($this->gate(), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-27'));

        for ($day = new \DateTimeImmutable('2026-09-14'); $day <= new \DateTimeImmutable('2026-09-27'); $day = $day->modify('+1 day')) {
            self::assertSame(
                ['day' => 2, 'night' => 2],
                $this->countsOn($planned, $day->format('Y-m-d')),
                \sprintf('%s should stand two days and two nights.', $day->format('Y-m-d')),
            );
        }
    }

    /**
     * Each person enters the ring one day later than the last — so on day one
     * of the ring the five of them are spread across all five positions, and
     * the one at the back is the one standing down.
     */
    public function testEachPersonEntersTheRingOneDayLaterThanTheLast(): void
    {
        $planned = new CyclePlanner()->plan($this->gate(), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-14'));

        $byPosition = [];
        foreach ($planned as $duty) {
            $byPosition[$duty->poolPosition] = $duty->shiftKey;
        }
        ksort($byPosition);

        // Position 0 is at ring day 1 (day), position 1 one day earlier in the
        // ring (off), position 2 at night, and so on round the back.
        self::assertSame([0 => 'day', 2 => 'night', 3 => 'night', 4 => 'day'], $byPosition);
    }

    /**
     * A SHORT POOL IS A HOLE, NOT A FAILURE. The ring keeps producing what it
     * can; the shortfall against what the station expects is what every
     * surface in the module draws as a hole.
     */
    public function testAPoolSmallerThanTheRingLeavesTheWatchShort(): void
    {
        $planned = new CyclePlanner()->plan($this->gate(poolSize: 3), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-14'));

        // Positions 0, 1 and 2 sit at ring days 1, 5 and 4 — a day, a stand
        // down and a night. The gate asks for two of each and gets one.
        self::assertSame(['day' => 1, 'night' => 1], $this->countsOn($planned, '2026-09-14'));
    }

    public function testAnEmptyPoolPlansNothingRatherThanDividingByZero(): void
    {
        self::assertSame([], new CyclePlanner()->plan($this->gate(poolSize: 0), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20')));
    }

    public function testARangeThatEndsBeforeItStartsPlansNothing(): void
    {
        self::assertSame([], new CyclePlanner()->plan($this->gate(), new \DateTimeImmutable('2026-09-20'), new \DateTimeImmutable('2026-09-14')));
    }

    /**
     * ASKING FOR ONE WEEK GIVES THE SAME ANSWER AS ASKING FOR THE MONTH THAT
     * CONTAINS IT. Without the warm-up scan, a rest rule would read "nothing
     * yesterday" on the first day of any range and roster a watch the same
     * rule forbids in the middle of a longer one.
     */
    public function testTheRangeAskedForDoesNotChangeTheAnswer(): void
    {
        $planner = new CyclePlanner();
        $plan = $this->gate(RestRule::NoNightThenDay);

        $wholeMonth = $planner->plan($plan, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        $oneWeek = $planner->plan($plan, new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-09-27'));

        $slice = array_values(array_filter(
            $wholeMonth,
            static fn (PlannedDuty $d): bool => $d->onDay >= new \DateTimeImmutable('2026-09-21') && $d->onDay <= new \DateTimeImmutable('2026-09-27'),
        ));

        self::assertEquals($slice, $oneWeek);
    }

    /**
     * A WELL-FORMED RING LOSES NOTHING TO THE RULE, and that has to be true
     * or the rule would be unusable: the gate's ring walks day, day, night,
     * night, off, so a night is always followed by another night and then a
     * stand-down. Turning the rule on must not empty a park that was already
     * being run properly.
     */
    public function testAWellFormedRingLosesNothingToTheRestRule(): void
    {
        $planner = new CyclePlanner();
        $from = new \DateTimeImmutable('2026-09-14');
        $through = new \DateTimeImmutable('2026-09-27');

        self::assertEquals(
            $planner->plan($this->gate(), $from, $through),
            $planner->plan($this->gate(RestRule::NoNightThenDay), $from, $through),
        );
    }

    /**
     * A RING THAT BREAKS THE RULE GENERATES A HOLE, NEVER AN ILLEGAL WATCH —
     * ruled, and this is the ring that makes it fire: night, then day,
     * repeating. Unruled it stands a watch every day; with the rule on, every
     * day watch that follows a night is dropped and the post comes up short.
     */
    public function testARingThatPutsADayStraightAfterANightGeneratesAHole(): void
    {
        $badRing = fn (RestRule $rule): RotationPlan => new RotationPlan(
            Cycle::of(['night', 'day']),
            1,
            new \DateTimeImmutable('2026-09-14'),
            [],
            $rule,
            $this->windows(),
        );

        $planner = new CyclePlanner();
        $from = new \DateTimeImmutable('2026-09-14');
        $through = new \DateTimeImmutable('2026-09-21');

        self::assertCount(8, $planner->plan($badRing(RestRule::None), $from, $through), 'Unruled, the ring stands a watch every day.');

        $ruled = $planner->plan($badRing(RestRule::NoNightThenDay), $from, $through);

        self::assertLessThan(8, \count($ruled), 'The rule has to remove something, or it is not being applied.');

        foreach ($this->consecutivePairs($ruled) as [$yesterday, $today]) {
            self::assertFalse(
                'night' === $yesterday->shiftKey && 'day' === $today->shiftKey,
                \sprintf('Pool position %d stands a day on %s straight off a night.', $today->poolPosition, $today->onDay->format('Y-m-d')),
            );
        }
    }

    /**
     * ELEVEN HOURS IS A STRICTER RULE THAN "no night then a day", and it has
     * to catch the same case: a night watch ending at 06:00 leaves zero hours
     * before a day watch starting at 06:00.
     */
    public function testElevenHoursBetweenWatchesRefusesTheSameMorning(): void
    {
        $plan = new RotationPlan(
            Cycle::of(['night', 'day']),
            1,
            new \DateTimeImmutable('2026-09-14'),
            [],
            RestRule::ElevenHoursBetween,
            $this->windows(),
        );

        $ruled = new CyclePlanner()->plan($plan, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-21'));

        self::assertNotSame([], $ruled);

        foreach ($this->consecutivePairs($ruled) as [$yesterday, $today]) {
            self::assertFalse('night' === $yesterday->shiftKey && 'day' === $today->shiftKey);
        }
    }

    /**
     * Two day watches on consecutive days are twelve hours apart, so the
     * eleven-hour rule must leave them alone — a rule that refused them would
     * empty every post in the park.
     */
    public function testElevenHoursBetweenLeavesTwoDayWatchesInARowAlone(): void
    {
        $plan = new RotationPlan(
            Cycle::of(['day', 'day', Cycle::OFF]),
            1,
            new \DateTimeImmutable('2026-09-14'),
            [],
            RestRule::ElevenHoursBetween,
            $this->windows(),
        );

        $planned = new CyclePlanner()->plan($plan, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-15'));

        self::assertCount(2, $planned);
    }

    /**
     * "Two day watches, saturdays stood down" is a real shape, and it is not
     * the ring's: a ring that could express it would have to be seven days
     * long and would stop being a two-on-one-off ring.
     */
    public function testAStoodDownWeekdayProducesNothingAtAll(): void
    {
        $plan = new RotationPlan(
            Cycle::of(['day', 'day', Cycle::OFF]),
            3,
            new \DateTimeImmutable('2026-09-14'),
            // Saturday.
            [6],
            RestRule::None,
            $this->windows(),
        );

        $planned = new CyclePlanner()->plan($plan, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        // Saturday 19 September 2026.
        self::assertSame([], $this->countsOn($planned, '2026-09-19'));
        self::assertNotSame([], $this->countsOn($planned, '2026-09-18'));
        self::assertNotSame([], $this->countsOn($planned, '2026-09-20'));
    }

    /**
     * A shift key the area's vocabulary cannot describe is an UNKNOWN gap, and
     * an unknown gap passes: a renamed vocabulary row must not empty the park.
     */
    public function testAShiftTheVocabularyCannotDescribeIsStillRostered(): void
    {
        $plan = new RotationPlan(
            Cycle::of(['twilight', 'twilight']),
            1,
            new \DateTimeImmutable('2026-09-14'),
            [],
            RestRule::ElevenHoursBetween,
            $this->windows(),
        );

        self::assertCount(2, new CyclePlanner()->plan($plan, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-15')));
    }

    public function testItPlansInDayOrderThenPoolOrder(): void
    {
        $planned = new CyclePlanner()->plan($this->gate(), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-16'));

        $seen = array_map(
            static fn (PlannedDuty $d): string => $d->onDay->format('Y-m-d').'#'.$d->poolPosition,
            $planned,
        );
        $sorted = $seen;
        sort($sorted);

        self::assertSame($sorted, $seen);
    }

    /**
     * @param list<PlannedDuty> $planned
     *
     * @return list<array{PlannedDuty, PlannedDuty}> one entry per person per pair of consecutive days worked
     */
    private function consecutivePairs(array $planned): array
    {
        $byPerson = [];
        foreach ($planned as $duty) {
            $byPerson[$duty->poolPosition][$duty->onDay->format('Y-m-d')] = $duty;
        }

        $pairs = [];
        foreach ($byPerson as $days) {
            ksort($days);
            $previous = null;
            foreach ($days as $date => $duty) {
                if (null !== $previous && $previous->onDay->modify('+1 day')->format('Y-m-d') === $date) {
                    $pairs[] = [$previous, $duty];
                }
                $previous = $duty;
            }
        }

        return $pairs;
    }
}
