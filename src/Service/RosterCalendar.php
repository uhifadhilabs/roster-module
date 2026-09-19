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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Contracts\Atlas\CalendarDay;
use Uhifadhi\Contracts\Atlas\CalendarFeedInterface;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Contracts\Atlas\CalendarPill;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * ONE RANGER'S MONTH, fed to the house calendar.
 *
 * THE ATLAS OWNS THE MONTH AND THIS OWNS WHAT IS IN IT. The grid, the day
 * head, the cell, its fixed height, the day number, the "+N more" and the
 * stepper are the atlas's, drawn the same way on every calendar in the
 * product. This module says only what happened on which day — which is the
 * whole bargain, and the reason the roster ships no month grid of its own.
 *
 * A PAST DAY CARRIES WHAT THE CHECK-IN RECORDED; A FUTURE DAY CARRIES WHAT
 * THE ROTATION PLANS, and the two never wear the same mark. That is the
 * design's own rule for this tab and it is what `closed` is for: a watch
 * that has been stood is FINISHED — a hollow mark — and one still to come is
 * open. Confusing them would let a plan read as a record.
 *
 * THE SCOPE IS A PERSON. A month of everybody's watches is the day board's
 * question, not this one; the calendar is the format the handset also draws,
 * and a handset has exactly one ranger in it.
 *
 * A REST DAY IS SIMPLY ABSENT. The contract keys only the days a feed has
 * something for, and a ring that stands somebody down produces no duty — so
 * an empty cell is a rest day without anything having to say so.
 */
final readonly class RosterCalendar implements CalendarFeedInterface
{
    public function __construct(
        private DutyRepository $duties,
        private ShiftRepository $shifts,
        private PresenceProviderInterface $presence,
        private AreaOfInterestRepository $areas,
    ) {
    }

    /**
     * ONE MONTH FOR ONE RANGER.
     *
     * The scope is `<area uuid>:<person uuid>` — this feed needs both, and
     * the contract's scope is one opaque string by design. A scope it cannot
     * read answers an empty month rather than throwing: the atlas draws an
     * empty September, which is a fact about September, where a missing grid
     * is a broken page.
     */
    public function month(YearMonth $month, ?string $scope = null): CalendarMonth
    {
        $subject = self::subjectOf($scope);
        if (null === $subject) {
            return CalendarMonth::none($month);
        }

        [$areaUuid, $personUuid] = $subject;
        $area = $this->areaFor($areaUuid);
        if (null === $area) {
            return CalendarMonth::none($month);
        }

        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $today = new \DateTimeImmutable('today');
        $days = [];

        foreach ($this->duties->findStandingForPersonBetween($area, $personUuid, $month->firstDay(), $month->lastDay()) as $duty) {
            $localDate = $duty->getOnDay()->format('Y-m-d');
            $past = $duty->getOnDay() < $today;

            $days[$localDate][] = $this->pillFor(
                $duty,
                $labels[$duty->getShiftKey()] ?? $duty->getShiftKey(),
                $past ? $this->presence->dayFor($areaUuid, $personUuid, $localDate)?->state : null,
                $past,
            );
        }

        $month_ = [];
        foreach ($days as $localDate => $pills) {
            $month_[$localDate] = new CalendarDay($localDate, $pills);
        }

        return new CalendarMonth($month, $month_);
    }

    /**
     * ONE WATCH AS A MARK.
     *
     * THE HUE IS A ROLE AND NOT A PAINT — the atlas chooses the colour. A
     * past watch reads as what the day turned out to be; a future one is
     * just a watch, and colouring it green would be the plan claiming to be
     * a record.
     */
    private function pillFor(Duty $duty, string $shiftLabel, ?DayState $state, bool $past): CalendarPill
    {
        if (!$past) {
            return new CalendarPill(
                label: mb_strtolower($shiftLabel),
                hue: PillHue::Subject,
                title: \sprintf('%s — %s, planned', $shiftLabel, $duty->getStation()->getName() ?? 'the post'),
            );
        }

        // A DAY WITH NO READING AT ALL is a watch nobody reported — which is
        // the "no check-in" the whole module is built to make visible, not a
        // day to draw as ordinary.
        $hue = match ($state) {
            DayState::AtPostVerified => PillHue::Good,
            DayState::AtPostUnverified => PillHue::Attention,
            DayState::WorkingElsewhere, DayState::Special => PillHue::Subject,
            DayState::NotWorking => PillHue::Quiet,
            default => PillHue::Problem,
        };

        return new CalendarPill(
            label: mb_strtolower($shiftLabel),
            hue: $hue,
            // FINISHED, so the mark is hollow: a watch that has been stood
            // is a record and must not read as a plan.
            closed: true,
            title: \sprintf('%s — %s', $shiftLabel, $state?->label() ?? DayState::NoCheckIn->label()),
        );
    }

    /**
     * THE SCOPE, SPLIT. `<area uuid>:<person uuid>`, and anything else is
     * unreadable rather than half-understood.
     *
     * @return array{string, string}|null
     */
    private static function subjectOf(?string $scope): ?array
    {
        if (null === $scope) {
            return null;
        }

        $parts = explode(':', $scope);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /** The one spelling of this feed's scope, so the controller and the feed cannot disagree. */
    public static function scopeFor(string $areaUuid, string $personUuid): string
    {
        return $areaUuid.':'.$personUuid;
    }

    private function areaFor(string $areaUuid): ?AreaOfInterest
    {
        if (!Uuid::isValid($areaUuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($areaUuid)]);
    }
}
