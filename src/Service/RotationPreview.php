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

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Atlas\CalendarDay;
use Uhifadhi\Contracts\Atlas\CalendarFeedInterface;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Contracts\Atlas\CalendarPill;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Model\RotationPlan;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE MONTH A ROTATION WOULD GENERATE — and it writes nothing at all.
 *
 * A PREVIEW WRITES NOTHING is the design's own line, and this is the class
 * that keeps it: the month under the cycle editor is the SAME pure planner
 * the generator runs, over the same ring, producing values rather than rows.
 * Nothing is persisted, no day is marked, and closing the tab leaves the
 * database exactly as it was.
 *
 * WHICH ALSO MEANS THE PREVIEW CANNOT DISAGREE WITH THE RESULT. A second
 * implementation drawn "close enough" would be a month that shows two
 * watches where the generator will write one, and nobody would find out
 * until the rota was published.
 *
 * IT SHOWS WHAT THE RING ASKS FOR AND WHAT IT CAN FILL. A day where the pool
 * cannot cover the declared slots is a HOLE, drawn as one — which is the
 * whole reason somebody edits a ring while watching a month.
 *
 * THE SCOPE IS A ROTATION. The atlas hands the feed an opaque string; here
 * it is the rotation's uuid, because a preview is about one ring.
 */
final readonly class RotationPreview implements CalendarFeedInterface
{
    public function __construct(
        private RotationRepository $rotations,
        private ShiftRepository $shifts,
        private CyclePlanner $planner,
    ) {
    }

    public function month(YearMonth $month, ?string $scope = null): CalendarMonth
    {
        $rotation = $this->rotationFor($scope);
        if (null === $rotation) {
            return CalendarMonth::none($month);
        }

        $station = $rotation->watchStation();
        if (null === $station) {
            return CalendarMonth::none($month);
        }

        $windows = [];
        $labels = [];
        foreach ($this->shifts->findByArea($rotation->getArea()) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $planned = $this->planner->plan(
            RotationPlan::fromRotation($rotation, $windows),
            $month->firstDay(),
            $month->lastDay(),
        );

        /** @var array<string, array<string, int>> $filled */
        $filled = [];
        foreach ($planned as $duty) {
            $day = $duty->onDay->format('Y-m-d');
            $filled[$day][$duty->shiftKey] = ($filled[$day][$duty->shiftKey] ?? 0) + 1;
        }

        $days = [];
        for ($day = $month->firstDay(); $day <= $month->lastDay(); $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $pills = [];

            foreach ($rotation->getSlotsPerShift() as $shiftKey => $expected) {
                if ($expected < 1 || $rotation->standsDownOn($day)) {
                    continue;
                }

                $got = $filled[$key][$shiftKey] ?? 0;
                $label = mb_strtolower($labels[$shiftKey] ?? $shiftKey);

                $pills[] = $got >= $expected
                    ? new CalendarPill(label: \sprintf('%s %d', $label, $got), hue: PillHue::Subject)
                    : new CalendarPill(
                        label: \sprintf('%s %d of %d', $label, $got, $expected),
                        hue: PillHue::Problem,
                        title: 'The ring cannot fill this watch from the pool as it stands.',
                    );
            }

            if ([] !== $pills) {
                $days[$key] = new CalendarDay($key, $pills);
            }
        }

        return new CalendarMonth($month, $days);
    }

    /** The one spelling of this feed's scope. */
    public static function scopeFor(Rotation $rotation): string
    {
        return $rotation->getUuid()->toRfc4122();
    }

    private function rotationFor(?string $scope): ?Rotation
    {
        if (null === $scope || !Uuid::isValid($scope)) {
            return null;
        }

        return $this->rotations->findOneBy(['uuid' => Uuid::fromString($scope)]);
    }
}
