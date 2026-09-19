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
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PersonWatch;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;
use Uhifadhi\Roster\Service\RosterFiguresService;

/**
 * THE FIVE FIGURES, COUNTED — the fold the overview leads with.
 *
 * IT IS TESTED WITHOUT A DATABASE ON PURPOSE. The fold is the only thing
 * this class does; everything it counts is somebody else's answer, so a test
 * that booted a kernel to reach it would be testing the presence seam again
 * and telling us nothing about the arithmetic.
 *
 * THE CASES THAT MATTER ARE THE ONES A DASHBOARD GETS WRONG: a post that
 * expects more than it has rostered, somebody who checked in twice in a day,
 * somebody the area returned nothing for, and a claim the pings do not bear
 * out — which is counted AND still counted as a check-in, because it
 * arrived.
 */
final class RosterFiguresServiceTest extends TestCase
{
    private function person(string $name, DayState ...$watches): RosteredPerson
    {
        $claims = [];
        foreach ($watches as $index => $state) {
            $claims[] = new PersonWatch(
                clientRef: $name.'-'.$index,
                state: $state,
                statusKey: 'at_post',
                statusLabel: 'At post',
                stationUuid: null,
                stationName: null,
            );
        }

        return new RosteredPerson(
            personUuid: $name,
            personName: $name,
            shiftKey: 'day',
            shiftLabel: 'Day',
            day: [] === $claims ? null : new PersonDay(
                personUuid: $name,
                personName: $name,
                localDate: '2026-09-19',
                state: $watches[\count($watches) - 1],
                watches: $claims,
            ),
        );
    }

    /**
     * @param list<RosteredPerson> $people
     */
    private function post(array $people, int $expected, PostState $state, int $verified = 0, int $flagged = 0): PostPresence
    {
        return new PostPresence(
            stationUuid: 'station-'.$expected.'-'.$state->value,
            stationName: 'A post',
            rostered: $people,
            expected: $expected,
            verified: $verified,
            flagged: $flagged,
            state: $state,
        );
    }

    public function testItCountsTheDayAcrossEveryPostOnTheBooks(): void
    {
        $figures = RosterFiguresService::fold([
            $this->post([
                $this->person('verified', DayState::AtPostVerified),
                $this->person('missing'),
            ], expected: 2, state: PostState::Reporting, verified: 1),
            $this->post([
                $this->person('flagged', DayState::AtPostUnverified),
            ], expected: 2, state: PostState::Late, flagged: 1),
        ], holes: 3, postsInTheArea: 12);

        self::assertSame(4, $figures->expected, 'The POSTS\' expectation, not the length of the rostered lists.');
        self::assertSame(2, $figures->checkedIn, 'A flagged claim still arrived.');
        self::assertSame(1, $figures->verified);
        self::assertSame(1, $figures->flagged);
        self::assertSame(1, $figures->noCheckIn);
        self::assertSame(1, $figures->postsReporting);
        self::assertSame(2, $figures->postsOnTheBooks);
        self::assertSame(12, $figures->postsInTheArea);
        self::assertSame(3, $figures->holes);
    }

    /** TWO WATCHES IN A DAY IS ONE PERSON who checked in, not two. */
    public function testADayOfTwoWatchesIsOnePersonCheckedIn(): void
    {
        $figures = RosterFiguresService::fold([
            $this->post([
                $this->person('twice', DayState::AtPostVerified, DayState::WorkingElsewhere),
            ], expected: 1, state: PostState::Reporting, verified: 1),
        ], holes: 0, postsInTheArea: 1);

        self::assertSame(1, $figures->checkedIn);
        self::assertSame(0, $figures->noCheckIn);
    }

    /**
     * AN AREA WITH NO POST ON THE BOOKS HAS NO PERCENTAGE. Nought reporting
     * out of nought is not a failing park, and a card that drew it as 0%
     * would be accusing one.
     */
    public function testAnAreaWithNoPostOnTheBooksSaysSoRatherThanScoringNought(): void
    {
        $figures = RosterFiguresService::fold([], holes: 0, postsInTheArea: 12);

        self::assertFalse($figures->hasABook());
        self::assertSame(0, $figures->expected);
        self::assertSame(12, $figures->postsInTheArea, 'The area still has its posts; this module just has none of them.');
    }

    /** WHAT NEEDS AN ANSWER is the three kinds of problem, added up. */
    public function testWhatNeedsADecisionIsTheThreeKindsTogether(): void
    {
        $figures = RosterFiguresService::fold([
            $this->post([
                $this->person('flagged', DayState::AtPostUnverified),
                $this->person('missing'),
            ], expected: 2, state: PostState::Late, flagged: 1),
        ], holes: 2, postsInTheArea: 3);

        self::assertSame(4, $figures->needingADecision());
    }
}
