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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Enum\ForbiddenDay;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Model\FillPlan;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * FILLING A STATION'S DAYS FROM A PATTERN.
 *
 * THREE THINGS IT NEVER DOES, and each of them is a ruling:
 *
 *   IT NEVER OVERWRITES A DAY SOMEBODY EDITED. A hand mark survives the
 *   thing it protects, so a day whose duty was removed by hand stays
 *   removed however many times the fill runs over it.
 *
 *   IT NEVER WRITES THE PAST. A fill is a plan, and rewriting a watch
 *   somebody already stood would be rewriting a record of what happened.
 *
 *   IT NEVER FILLS WRONGLY. A day the area's own rules forbid is left as
 *   a gap anybody can see, or filled and flagged as needing a decision —
 *   which of the two is the area's choice, and there is no third answer
 *   where the rule is quietly not applied.
 *
 * AND A LATER START DATE CHANGES ONLY THE DAYS AFTER IT. The ring stays
 * anchored on the day the station was first filled from, so running the
 * fill again from next monday does not slide a fortnight of standing
 * watches sideways under the people standing them.
 *
 * PREVIEW AND FILL ARE ONE WALK. The number somebody reads before
 * pressing the button is produced by the same code that produces the
 * number they get.
 */
final readonly class SheetFillService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PostingRepository $postings,
        private StationWatchRepository $watches,
        private DutyRepository $duties,
        private EditedDayRepository $marks,
        private ShiftRepository $shifts,
        private ShiftRuleService $rules,
        private ClockInterface $clock,
    ) {
    }

    /** WHAT IT WOULD DO — nothing is written and nothing is flushed. */
    public function preview(Station $station, Pattern $pattern, \DateTimeImmutable $from): FillPlan
    {
        return $this->walk($station, $pattern, $from, false);
    }

    /** AND DOING IT. */
    public function fill(Station $station, Pattern $pattern, \DateTimeImmutable $from): FillPlan
    {
        $plan = $this->walk($station, $pattern, $from, true);

        $watch = $this->watches->findOneForStation($station);
        $watch?->filledFrom($plan->from, $plan->through);

        $this->entityManager->flush();

        return $plan;
    }

    /**
     * HOW FAR AHEAD THIS STATION FILLS — the area's rule, or the
     * station's own where it has one.
     */
    public function horizonDays(Station $station): int
    {
        $ahead = $this->rules->effective($station, RuleKind::FillAhead);

        return max(1, (int) round($ahead->value) * $ahead->unit->days());
    }

    /**
     * THE ONE WALK.
     *
     * @param bool $write false is the preview: every question is asked and no answer is stored
     */
    private function walk(Station $station, Pattern $pattern, \DateTimeImmutable $from, bool $write): FillPlan
    {
        $area = $station->getArea();
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        // NEVER THE PAST. A start date behind today is honoured from
        // today onward rather than refused: somebody typing last monday
        // means "from the ring's own beginning", not "rewrite last week".
        $start = max($from->setTime(0, 0), $today);
        $through = $start->modify(\sprintf('+%d days', $this->horizonDays($station) - 1));

        if (null === $area) {
            return new FillPlan($start, $through, 0, 0, 0);
        }

        $watch = $this->watches->findOneForStation($station);
        $anchor = $watch?->getPatternFrom() ?? $start;
        $cycle = $pattern->getCycle();
        $windows = $this->shiftWindows($station);

        $rest = $this->rules->effective($station, RuleKind::RestBetween)->toMinutes();
        $nightThenDay = $this->rules->nightThenDayAt($station);
        $forbiddenDay = $this->rules->forbiddenDayAt($station);

        $people = $this->seatsAt($station);
        $marked = $this->markedDays($station, $start, $through);
        // ONE DAY BEFORE THE WINDOW, because the rest rule is about the
        // watch BEFORE the first one this fill writes.
        $standing = $this->standingDays($station, $start->modify('-1 day'), $through);

        $days = 0;
        $leftAlone = 0;
        $forbidden = 0;

        for ($day = $start; $day <= $through; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');

            foreach ($people as $seat => $person) {
                $uuid = (string) $person->getUuidString();

                $offset = (int) $anchor->diff($day)->format('%r%a') - $seat;
                $shiftKey = $cycle->at($offset);
                if ($cycle::OFF === $shiftKey || !isset($windows[$shiftKey])) {
                    continue;
                }

                if (isset($marked[$uuid][$key]) || isset($marked['*'][$key])) {
                    ++$leftAlone;

                    continue;
                }

                if (isset($standing[$uuid][$key])) {
                    continue;
                }

                $blocked = self::forbids($standing[$uuid][$day->modify('-1 day')->format('Y-m-d')] ?? null, $windows[$shiftKey], $rest, $nightThenDay);

                if (null !== $blocked) {
                    ++$forbidden;

                    if (ForbiddenDay::LeftUnfilled === $forbiddenDay) {
                        continue;
                    }
                }

                ++$days;
                $standing[$uuid][$key] = $windows[$shiftKey];

                if (!$write) {
                    continue;
                }

                $duty = new Duty($area, $station, $person, $shiftKey, $day);
                if (null !== $blocked) {
                    $duty->setNote($blocked);
                }
                $this->entityManager->persist($duty);
            }
        }

        return new FillPlan($start, $through, $days, $leftAlone, $forbidden);
    }

    /**
     * WHETHER THE RULES FORBID PUTTING THIS WATCH AFTER THAT ONE, and
     * why — the reason is the note a flagged day carries, because "this
     * day was filled against a rule" is useless without which rule.
     */
    private static function forbids(?ShiftWindow $previous, ShiftWindow $next, int $restMinutes, NightThenDay $nightThenDay): ?string
    {
        if (null === $previous) {
            return null;
        }

        if (!$nightThenDay->permits() && $previous->crossesMidnight() && !$next->crossesMidnight()) {
            return 'a day watch the morning after a night watch';
        }

        // The previous watch ends this many minutes after the start of
        // its own day; the next begins that many after the start of the
        // day after. The gap between them is the rest.
        $gap = 1440 + $next->startsAtMinuteOfDay() - $previous->endsAtMinuteFromItsDay();

        return $gap < $restMinutes ? \sprintf('less than %d minutes of rest', $restMinutes) : null;
    }

    /**
     * THE RANGERS STATIONED HERE, IN SEAT ORDER — the same order the
     * sheet draws them in, because a seat that disagreed with the row it
     * is drawn on would fill the wrong person's day.
     *
     * @return list<UserInterface>
     */
    private function seatsAt(Station $station): array
    {
        $people = [];
        foreach ($this->postings->findStandingByStation($station) as $posting) {
            $person = $posting->getPerson();
            if (null !== $person) {
                $people[] = $person;
            }
        }

        usort($people, static fn (UserInterface $a, UserInterface $b): int => [$a->getFullName(), (string) $a->getUuidString()] <=> [$b->getFullName(), (string) $b->getUuidString()]);

        return $people;
    }

    /**
     * THE DAYS A HAND HAS TOUCHED HERE, by ranger; a station-wide mark
     * files under `*` and protects every seat.
     *
     * @return array<string, array<string, true>>
     */
    private function markedDays(Station $station, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $marked = [];
        foreach ($this->marks->findByStationBetween($station, $from, $through) as $mark) {
            $person = $mark->getPerson();
            $marked[null === $person ? '*' : (string) $person->getUuidString()][$mark->getOnDay()->format('Y-m-d')] = true;
        }

        return $marked;
    }

    /**
     * WHAT EACH RANGER IS ALREADY STANDING, as the window of the watch —
     * every station, not only this one, because rest is a person's and
     * not a place's.
     *
     * @return array<string, array<string, ShiftWindow>>
     */
    private function standingDays(Station $station, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $area = $station->getArea();
        if (null === $area) {
            return [];
        }

        $windows = $this->shiftWindows($station);

        $standing = [];
        foreach ($this->duties->findByAreaBetween($area, $from, $through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $window = $windows[$duty->getShiftKey()] ?? null;
            if (null !== $window) {
                $standing[(string) $duty->getPerson()->getUuidString()][$duty->getOnDay()->format('Y-m-d')] = $window;
            }
        }

        return $standing;
    }

    /**
     * THE AREA'S SHIFT WINDOWS, by key. A ring position naming a shift
     * this area does not have is skipped rather than guessed at: a
     * pattern written before a shift was closed must not conjure it back.
     *
     * @return array<string, ShiftWindow>
     */
    private function shiftWindows(Station $station): array
    {
        $area = $station->getArea();
        if (null === $area) {
            return [];
        }

        $windows = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
        }

        return $windows;
    }
}
