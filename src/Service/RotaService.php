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
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\RotaCell;
use Uhifadhi\Roster\Model\RotaCellKind;
use Uhifadhi\Roster\Model\RotaFigures;
use Uhifadhi\Roster\Model\RotaGroup;
use Uhifadhi\Roster\Model\RotaRow;
use Uhifadhi\Roster\Model\SwapMark;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;

/**
 * THE ROTA — PEOPLE DOWN, GROUPED BY POST, A FORTNIGHT ACROSS.
 *
 * THE ROW IS THE WHOLE POINT. A grid of POSTS cannot answer "who is working
 * too many nights", which is the question a planner opens this tab for; a
 * grid of PEOPLE can, because the answer is one row long. That is why the
 * geometry changed, and it is why the figures above it are per person.
 *
 * A FORTNIGHT AND NOT A WEEK, monday-anchored. Two weeks is the window a
 * swap is actually arranged in — long enough to see where somebody's rest
 * falls, short enough to read across a screen.
 *
 * ONLY TODAY'S COLUMN CARRIES A CHECK-IN STATE. Every other column is what
 * the rotation SAYS, not what happened. A grid that painted "verified" on a
 * future cell would state a plan as a fact, which is the one thing this
 * module exists not to do — so the presence read is asked for exactly one
 * day and every other cell is left as the plan.
 *
 * FOUR QUERIES FOR THE WHOLE FORTNIGHT, not one per cell: fourteen days
 * across eighteen people is two hundred and fifty cells, and a planner's tab
 * that queried per cell would be slower than the month it plans.
 */
final readonly class RotaService
{
    /** A fortnight. */
    public const int DAYS = 14;

    public function __construct(
        private StationWatchRepository $watches,
        private RotationRepository $rotations,
        private RotationPoolMemberRepository $pool,
        private DutyRepository $duties,
        private ShiftRepository $shifts,
        private SwapRepository $swaps,
        private PresenceReader $presence,
    ) {
    }

    /**
     * THE MONDAY THE FORTNIGHT STARTS ON. A window that began on the day
     * somebody happened to look is not a window two people can compare
     * notes in.
     */
    public static function start(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('monday this week');
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function days(\DateTimeImmutable $from): array
    {
        $days = [];
        for ($i = 0; $i < self::DAYS; ++$i) {
            $days[] = $from->setTime(0, 0)->modify(\sprintf('+%d days', $i));
        }

        return $days;
    }

    /**
     * THE GRID: one group per post on the books, each with one row per
     * person its ring draws from.
     *
     * @return list<RotaGroup>
     */
    public function groups(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $from = $from->setTime(0, 0);
        $through = $through->setTime(0, 0);

        $shifts = $this->shiftsOf($area);
        $duties = $this->dutiesByPersonDay($area, $from, $through);
        $swaps = $this->swapMarks($area, $from, $through);
        $today = new \DateTimeImmutable('today');
        $states = $this->statesToday($area, $today, $from, $through);

        $groups = [];
        foreach ($this->watches->findByArea($area) as $watch) {
            $station = $watch->getStation();
            $rotation = $this->rotations->findOneForStation($station);

            $rows = [];
            foreach (null === $rotation ? [] : $this->pool->findOrdered($rotation) as $member) {
                $person = $member->getPerson();
                $uuid = (string) $person->getUuidString();

                $cells = [];
                foreach ($this->days($from) as $day) {
                    $key = $day->format('Y-m-d');
                    $cells[$key] = $this->cellFor(
                        $duties[$uuid][$key] ?? null,
                        $swaps[$uuid][$key] ?? null,
                        $shifts,
                        $key === $today->format('Y-m-d'),
                        $states[$uuid] ?? null,
                    );
                }

                $rows[] = new RotaRow(
                    personUuid: $uuid,
                    personName: $person->getFullName(),
                    position: null,
                    cells: $cells,
                );
            }

            $groups[] = new RotaGroup(
                stationUuid: (string) $station->getUuidString(),
                stationName: (string) $station->getName(),
                stationCode: $station->getCode(),
                asks: $this->asksOf($watch->getExpects(), $rotation, $shifts),
                rows: $rows,
            );
        }

        return $groups;
    }

    /**
     * THE FIVE FIGURES, every one measuring the same fortnight the grid
     * draws.
     */
    public function figures(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): RotaFigures
    {
        $groups = $this->groups($area, $from, $through);

        $watches = 0;
        $heaviest = 0;
        $heaviestName = null;
        foreach ($groups as $group) {
            foreach ($group->rows as $row) {
                $watches += $row->watches();
                if ($row->nights() > $heaviest) {
                    $heaviest = $row->nights();
                    $heaviestName = $row->personName;
                }
            }
        }

        $flagged = 0;
        foreach ($this->presence->postsOn($area, new \DateTimeImmutable('today')) as $post) {
            $flagged += $post->flagged;
        }

        return new RotaFigures(
            personWatches: $watches,
            holes: $this->holesIn($area, $from, $through),
            heaviestNights: $heaviest,
            heaviestName: $heaviestName,
            flaggedToday: $flagged,
            swapsPending: \count($this->swaps->findOpenBetween($area, $from, $through)),
        );
    }

    /**
     * ONE CELL. The swap mark WINS over the watch it is about, because a
     * trade in flight is the thing a planner has to see on that day — and
     * neither duty has moved, which is what the mark says.
     *
     * @param array<string, Shift> $shifts
     */
    private function cellFor(?Duty $duty, ?SwapMark $swap, array $shifts, bool $isToday, ?DayState $state): RotaCell
    {
        if (null !== $swap) {
            return new RotaCell(
                kind: $swap->kind,
                label: $swap->label,
                window: '—',
                isToday: $isToday,
                state: $isToday ? $state : null,
                note: $swap->note,
            );
        }

        if (null === $duty) {
            return new RotaCell(RotaCellKind::Off, 'off', '—', $isToday, $isToday ? $state : null);
        }

        $shift = $shifts[$duty->getShiftKey()] ?? null;
        $crosses = null !== $shift && $shift->crossesMidnight();

        return new RotaCell(
            kind: $crosses ? RotaCellKind::Night : RotaCellKind::Day,
            label: mb_strtolower(null === $shift ? $duty->getShiftKey() : $shift->getLabel()),
            window: null === $shift ? '—' : $shift->getStartsAt().'–'.$shift->getEndsAt(),
            isToday: $isToday,
            state: $isToday ? $state : null,
        );
    }

    /**
     * WHO IS ON WHAT, keyed person and day — one query for the fortnight.
     *
     * A person with two watches on one day keeps the FIRST for the grid: a
     * cell is one square and the agenda is where a day's whole list is
     * read. The grid says "on", not "on how many times".
     *
     * @return array<string, array<string, Duty>>
     */
    private function dutiesByPersonDay(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $byPerson = [];
        foreach ($this->duties->findByAreaBetween($area, $from, $through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $uuid = (string) $duty->getPerson()->getUuidString();
            $day = $duty->getOnDay()->format('Y-m-d');
            $byPerson[$uuid][$day] ??= $duty;
        }

        return $byPerson;
    }

    /**
     * THE TWO MARKS AN OPEN OFFER PUTS ON THE GRID — swapA on the watch
     * being given up, swapB on the person being asked.
     *
     * ONLY AN OPEN OFFER MARKS ANYTHING. An accepted swap has already moved
     * the duty, so the grid shows the roster as it now is; a declined or
     * withdrawn one changed nothing and marking it would be the grid
     * remembering a conversation instead of stating a plan.
     *
     * @return array<string, array<string, SwapMark>>
     */
    private function swapMarks(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $marks = [];
        foreach ($this->swaps->findOpenBetween($area, $from, $through) as $swap) {
            $duty = $swap->getDuty();
            $day = $duty->getOnDay()->format('Y-m-d');
            $station = $duty->getStation();

            $marks[(string) $duty->getPerson()->getUuidString()][$day] = new SwapMark(
                RotaCellKind::SwapGiven,
                'was '.$duty->getShiftKey(),
                'offered on',
            );

            $marks[(string) $swap->getOfferedTo()->getUuidString()][$day] = new SwapMark(
                RotaCellKind::SwapTaken,
                $duty->getShiftKey(),
                ($station->getCode() ?? (string) $station->getName()).' · taking it',
            );
        }

        return $marks;
    }

    /**
     * HOW EACH PERSON'S DAY READS — TODAY ONLY, and only when today falls
     * inside the window being drawn. Asking for a day the grid does not
     * show would be a query for a column nobody can see.
     *
     * @return array<string, DayState>
     */
    private function statesToday(AreaOfInterest $area, \DateTimeImmutable $today, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        if ($today < $from || $today > $through) {
            return [];
        }

        $states = [];
        foreach ($this->presence->postsOn($area, $today) as $post) {
            foreach ($post->rostered as $person) {
                $states[$person->personUuid] = $person->state();
            }
        }

        return $states;
    }

    /**
     * WHAT THE POST ASKS FOR, in the words the heading prints.
     *
     * @param list<string>         $expects
     * @param array<string, Shift> $shifts
     */
    private function asksOf(array $expects, ?Rotation $rotation, array $shifts): string
    {
        if (null === $rotation || [] === $expects) {
            return 'no watch';
        }

        $parts = [];
        foreach ($expects as $key) {
            $slots = $rotation->slotsFor($key);
            if ($slots > 0) {
                $parts[] = mb_strtolower(null === ($shifts[$key] ?? null) ? $key : $shifts[$key]->getLabel()).' '.$slots;
            }
        }

        return [] === $parts ? 'no watch' : implode(' · ', $parts);
    }

    /**
     * EVERY WATCH THE RINGS ASKED FOR AND NOBODY IS ON, over the window.
     */
    private function holesIn(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): int
    {
        $filled = [];
        foreach ($this->duties->findByAreaBetween($area, $from, $through) as $duty) {
            if ($duty->getState()->isStanding()) {
                $filled[(string) $duty->getStation()->getUuidString()][$duty->getOnDay()->format('Y-m-d')][$duty->getShiftKey()]
                    = ($filled[(string) $duty->getStation()->getUuidString()][$duty->getOnDay()->format('Y-m-d')][$duty->getShiftKey()] ?? 0) + 1;
            }
        }

        $holes = 0;
        foreach ($this->watches->findByArea($area) as $watch) {
            $station = $watch->getStation();
            $rotation = $this->rotations->findOneForStation($station);
            if (null === $rotation) {
                continue;
            }

            $uuid = (string) $station->getUuidString();
            foreach ($this->days($from) as $day) {
                if ($rotation->standsDownOn($day)) {
                    continue;
                }

                foreach ($watch->getExpects() as $key) {
                    $holes += max(0, $rotation->slotsFor($key) - ($filled[$uuid][$day->format('Y-m-d')][$key] ?? 0));
                }
            }
        }

        return $holes;
    }

    /**
     * @return array<string, Shift>
     */
    private function shiftsOf(AreaOfInterest $area): array
    {
        $shifts = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $shifts[$shift->getKey()] = $shift;
        }

        return $shifts;
    }
}
