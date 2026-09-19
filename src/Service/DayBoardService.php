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
use Uhifadhi\Roster\Model\BoardBlock;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE DAY AS A WALL — twenty-four hours across, one post per row, a block
 * for every watch.
 *
 * A NIGHT WATCH IS TWO BLOCKS, and this is the class that says so. It runs
 * 18:00 to 06:00, so on the day it begins it occupies the last quarter of
 * the row, and on the NEXT day it occupies the first quarter — the same
 * watch, drawn where the hours actually are. Drawing it as one block would
 * be a lie about the day it belongs to, and drawing only the first part
 * would leave every morning before six looking unmanned.
 *
 * THE GEOMETRY IS A PERCENTAGE OF THE DAY because the row is a proportion
 * of whatever width the screen gives it. Minutes since midnight over the
 * day's minutes — nothing about it depends on a pixel.
 */
final readonly class DayBoardService
{
    private const int MINUTES_IN_A_DAY = 24 * 60;

    public function __construct(
        private DutyRepository $duties,
        private ShiftRepository $shifts,
    ) {
    }

    /**
     * EVERY BLOCK ON ONE DAY, keyed by the post it belongs to.
     *
     * The day is asked for TWICE — this day and the one before — because a
     * night watch that began yesterday is still standing at 05:00 this
     * morning, and a board that only read today would draw an empty gate
     * for the hours somebody was actually on it.
     *
     * @return array<string, list<BoardBlock>> station uuid => its blocks, left to right
     */
    public function blocksFor(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        /** @var array<string, ShiftWindow> $windows */
        $windows = [];
        /** @var array<string, string> $labels */
        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $day = $day->setTime(0, 0);
        $yesterday = $day->modify('-1 day');

        /** @var array<string, array<string, BoardBlock>> $byStation */
        $byStation = [];

        foreach ($this->duties->findByAreaBetween($area, $yesterday, $day) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $window = $windows[$duty->getShiftKey()] ?? null;
            if (null === $window) {
                continue;
            }

            $label = $labels[$duty->getShiftKey()] ?? $duty->getShiftKey();

            $startedYesterday = $duty->getOnDay() < $day;
            $station = (string) $duty->getStation()->getUuidString();

            foreach ($this->spansOn($window, $startedYesterday) as $index => [$left, $width]) {
                // ONE BLOCK PER WATCH PER POST, not one per person: a gate
                // standing two on the night watch is one block saying "2",
                // which is what a wall on an office wall has room to say.
                $key = $duty->getShiftKey().'#'.($startedYesterday ? 'y' : 't').'#'.$index;

                $existing = $byStation[$station][$key] ?? null;
                $already = null === $existing ? [] : $existing->people;
                $byStation[$station][$key] = new BoardBlock(
                    shiftKey: $duty->getShiftKey(),
                    label: $label,
                    leftPercent: $left,
                    widthPercent: $width,
                    people: [...$already, $duty->getPerson()->getFullName()],
                    fromYesterday: $startedYesterday,
                    crossesMidnight: $window->crossesMidnight(),
                );
            }
        }

        $blocks = [];
        foreach ($byStation as $station => $keyed) {
            $ordered = array_values($keyed);
            usort($ordered, static fn (BoardBlock $a, BoardBlock $b): int => $a->leftPercent <=> $b->leftPercent);
            $blocks[$station] = $ordered;
        }

        return $blocks;
    }

    /**
     * WHERE A WATCH SITS ON THE DAY BEING DRAWN, as [left %, width %].
     *
     * A watch that does not cross midnight is one span on its own day and
     * nothing on the next. One that DOES is two: the evening of the day it
     * began, and the morning of the day after.
     *
     * @return list<array{float, float}>
     */
    private function spansOn(ShiftWindow $window, bool $startedYesterday): array
    {
        if (!$window->crossesMidnight()) {
            // Yesterday's day watch is over; it does not appear on today.
            return $startedYesterday ? [] : [[
                self::percent($window->startsAtMinuteOfDay()),
                self::percent($window->lengthMinutes()),
            ]];
        }

        return $startedYesterday
            // The morning tail of a watch that began last night.
            ? [[0.0, self::percent($window->endsAtMinuteFromItsDay() - self::MINUTES_IN_A_DAY)]]
            // The evening head of a watch beginning tonight.
            : [[
                self::percent($window->startsAtMinuteOfDay()),
                self::percent(self::MINUTES_IN_A_DAY - $window->startsAtMinuteOfDay()),
            ]];
    }

    /** How far through the day an instant is, as a percentage. */
    public function percentOfDay(\DateTimeImmutable $at): float
    {
        return self::percent(((int) $at->format('G') * 60) + (int) $at->format('i'));
    }

    private static function percent(int $minutes): float
    {
        return round($minutes / self::MINUTES_IN_A_DAY * 100, 4);
    }
}
