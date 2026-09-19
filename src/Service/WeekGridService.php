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
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\WeekCell;
use Uhifadhi\Roster\Model\WeekRow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * POSTS DOWN, DAYS ACROSS — the planner's surface, and the only one where a
 * hole is visible before the day arrives.
 *
 * EVERY HOLE IS DRAWN AS A HOLE. That is the whole reason this tab exists,
 * and it is why every cell carries what the post ASKED FOR beside what it
 * got: the difference is the hole, and a grid that only printed the duties
 * it found would draw a perfectly tidy week over a post nobody is on.
 *
 * TWO QUERIES FOR THE WHOLE WEEK, not one per cell. Seven days across six
 * posts is forty-two cells, and asking per cell is how a planner's tab ends
 * up slower than the month it plans.
 *
 * NOTHING HERE IS PRESENCE. The grid says who is DUE; whether they came is
 * the area's reading and belongs on the tabs that ask that question.
 */
final readonly class WeekGridService
{
    public function __construct(
        private StationWatchRepository $watches,
        private DutyRepository $duties,
        private RotationRepository $rotations,
        private ShiftRepository $shifts,
    ) {
    }

    /**
     * THE WEEK, POST BY POST.
     *
     * @return list<WeekRow> in the watches' own order, which is by call sign
     */
    public function rows(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $watches = $this->watches->findByArea($area);
        if ([] === $watches) {
            return [];
        }

        $initials = $this->initials($area);
        $filled = $this->filledCounts($area, $from, $through);

        $rows = [];
        foreach ($watches as $watch) {
            $station = $watch->getStation();
            $uuid = (string) $station->getUuidString();
            $rotation = $this->rotations->findOneForStation($station);
            $expects = $watch->getExpects();

            $cells = [];
            for ($day = $from->setTime(0, 0); $day <= $through->setTime(0, 0); $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');

                // A STOOD-DOWN DAY HAS NO CELLS AT ALL, which is not the same
                // as an empty one: the ring did not ask for anybody, so there
                // is no hole to draw.
                if ([] === $expects || null === $rotation || $rotation->standsDownOn($day)) {
                    continue;
                }

                $onTheDay = [];
                foreach ($expects as $shiftKey) {
                    $expected = $rotation->slotsFor($shiftKey);
                    if ($expected < 1) {
                        continue;
                    }

                    $onTheDay[] = new WeekCell(
                        shiftKey: $shiftKey,
                        initial: $initials[$shiftKey] ?? mb_strtoupper(mb_substr($shiftKey, 0, 1)),
                        filled: $filled[$uuid][$key][$shiftKey] ?? 0,
                        expected: $expected,
                    );
                }

                if ([] !== $onTheDay) {
                    $cells[$key] = $onTheDay;
                }
            }

            $rows[] = new WeekRow(
                stationUuid: $uuid,
                stationName: (string) $station->getName(),
                stationCode: $station->getCode(),
                cells: $cells,
                runsNoWatch: [] === $expects,
            );
        }

        return $rows;
    }

    /**
     * THE DAYS THE GRID DRAWS, as objects the template iterates rather than
     * arithmetic it repeats per row.
     *
     * @return list<\DateTimeImmutable>
     */
    public function days(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $days = [];
        for ($day = $from->setTime(0, 0); $day <= $through->setTime(0, 0); $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * THE MONDAY OF THE WEEK A DAY FALLS IN. The grid starts on a monday
     * whatever day somebody opens it — a week that began on the day you
     * happened to look is not a week anybody plans in.
     */
    public static function weekStart(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('monday this week');
    }

    /**
     * HOW MANY ARE ON EACH WATCH — counted in one query for the whole week.
     *
     * @return array<string, array<string, array<string, int>>> station uuid => Y-m-d => shift key => how many
     */
    private function filledCounts(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $counts = [];
        foreach ($this->duties->findByAreaBetween($area, $from, $through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $station = (string) $duty->getStation()->getUuidString();
            $day = $duty->getOnDay()->format('Y-m-d');
            $counts[$station][$day][$duty->getShiftKey()] = ($counts[$station][$day][$duty->getShiftKey()] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * THE ONE-LETTER MARK A CELL PRINTS, taken from the area's own shift
     * labels rather than hard-coded: a deployment that renames "office" to
     * "headquarters" gets H, and nothing in this module has to be told.
     *
     * @return array<string, string>
     */
    private function initials(AreaOfInterest $area): array
    {
        $initials = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $initials[$shift->getKey()] = mb_strtoupper(mb_substr($shift->getLabel(), 0, 1));
        }

        return $initials;
    }

    /**
     * @return list<Shift>
     *
     * @internal exposed for the template's key line, which names the windows the grid's letters stand for
     */
    public function shiftsOf(AreaOfInterest $area): array
    {
        return $this->shifts->findByArea($area);
    }

    /**
     * EVERY UNFILLED WATCH IN A WINDOW, SOONEST FIRST.
     *
     * Ordered by when the watch STARTS and not by when the hole was noticed:
     * a hole three hours away outranks one next week however long it has
     * been open.
     *
     * @return list<array{station: string, code: string|null, day: \DateTimeImmutable, shiftKey: string, label: string, filled: int, expected: int}>
     */
    public function gaps(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $gaps = [];
        foreach ($this->rows($area, $from, $through) as $row) {
            foreach ($row->cells as $day => $cells) {
                foreach ($cells as $cell) {
                    if (!$cell->isShort()) {
                        continue;
                    }

                    $gaps[] = [
                        'station' => $row->stationName,
                        'code' => $row->stationCode,
                        'day' => new \DateTimeImmutable($day),
                        'shiftKey' => $cell->shiftKey,
                        'label' => $labels[$cell->shiftKey] ?? $cell->shiftKey,
                        'filled' => $cell->filled,
                        'expected' => $cell->expected,
                    ];
                }
            }
        }

        usort($gaps, static fn (array $a, array $b): int => [$a['day'], $a['shiftKey']] <=> [$b['day'], $b['shiftKey']]);

        return $gaps;
    }
}
