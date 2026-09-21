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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Model\Sheet;
use Uhifadhi\Roster\Model\SheetBand;
use Uhifadhi\Roster\Model\SheetCell;
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetRow;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE PLANNING SHEET, READ — people down, days across, per station.
 *
 * FOUR QUERIES FOR THE WHOLE WINDOW, whatever its size. Thirty-four
 * rangers over twenty-eight days is 952 cells; asking anything per cell
 * is how a planner's tab ends up slower than the month it plans. The
 * stations, the postings, the duties and the hand marks each come back
 * once and are indexed here.
 *
 * WHAT MAKES A CELL UNFILLED, and it is the whole reason this tab
 * exists. A day is unfilled when the station's own cycle expects somebody
 * on it and nobody is — not when a duty happens to be missing, because a
 * missing duty on a day nothing was asked of is simply an off day. So the
 * expectation is computed from the station's pattern, anchored on the day
 * its fill started, offset by the ranger's seat: each person enters the
 * ring one day later than the last, which is how one cycle staffs a
 * station without anybody writing a rota.
 *
 * AND A STATION NOBODY HAS FILLED EXPECTS NOTHING. Null anchor, no
 * expectation, no alarm ink — a fortnight of it at a station nobody has
 * made a decision about would be the sheet shouting at somebody who has
 * done nothing wrong.
 *
 * NOTHING HERE IS PRESENCE. The sheet says who is DUE; whether they came
 * is the area's reading and belongs on the tabs that ask that question.
 */
final readonly class SheetService
{
    public function __construct(
        private StationRepository $stations,
        private PostingRepository $postings,
        private StationWatchRepository $watches,
        private DutyRepository $duties,
        private EditedDayRepository $marks,
        private ShiftRepository $shifts,
    ) {
    }

    /**
     * THE WHOLE SHEET FOR ONE WINDOW.
     *
     * NO STATION FILTER HERE, on purpose: narrowing is
     * {@see Sheet::only()}, applied to one read, because the head's chip
     * has to list every station whatever the sheet under it shows.
     */
    public function read(AreaOfInterest $area, SheetWindow $window): Sheet
    {
        $stations = $this->stations->findByArea($area);
        /*
         * BY CALL SIGN, which is how the sheet is read and how the
         * station filter lists them: ST-01 under ST-01. A station with
         * no code sorts last under its name rather than first under an
         * empty string, because an unlabelled place is not the first
         * place anybody looks.
         */
        usort($stations, static fn (Station $a, Station $b): int => [null === $a->getCode(), (string) $a->getCode(), (string) $a->getName()] <=> [null === $b->getCode(), (string) $b->getCode(), (string) $b->getName()]);
        $people = $this->peopleByStation($area);
        $watches = $this->watchesByStation($area);
        $duties = $this->dutiesByPersonAndDay($area, $window);
        $marks = $this->marksByPersonAndDay($area, $window);
        $shifts = $this->shiftFacts($area);
        $days = $window->days();

        $bands = [];
        foreach ($stations as $station) {
            $uuid = (string) $station->getUuidString();
            $expected = $this->expectationOf($watches[$uuid] ?? null);

            $rows = [];
            foreach ($people[$uuid] ?? [] as $seat => $person) {
                $cells = [];
                foreach ($days as $day) {
                    $cells[] = $this->cell(
                        $day,
                        $window,
                        $duties[$person['uuid']][$day->format('Y-m-d')] ?? null,
                        $marks[$person['uuid']][$day->format('Y-m-d')] ?? null,
                        null === $expected ? null : $expected($day, $seat),
                        $shifts,
                    );
                }

                $rows[] = new SheetRow(
                    personUuid: $person['uuid'],
                    personName: $person['name'],
                    stationCode: $station->getCode(),
                    seat: $seat,
                    cells: $cells,
                );
            }

            $bands[] = new SheetBand(
                stationUuid: $uuid,
                stationName: (string) $station->getName(),
                stationCode: $station->getCode(),
                rows: $rows,
            );
        }

        return new Sheet($window, $bands, \count($stations));
    }

    /**
     * ONE DAY OF ONE ROW.
     *
     * THE ORDER OF THE QUESTIONS IS THE RULING. A duty that stands is a
     * watch whatever else is true of the day; then the hand, which says
     * which of the two empty answers it meant; then the cycle, which is
     * what turns an ordinary empty day into a gap.
     *
     * @param array{key: string, uuid: string}|null            $duty
     * @param array{leftOff: bool}|null                        $mark
     * @param array<string, array{label: string, colour: int}> $shifts
     */
    private function cell(
        \DateTimeImmutable $day,
        SheetWindow $window,
        ?array $duty,
        ?array $mark,
        ?string $expects,
        array $shifts,
    ): SheetCell {
        $isToday = $day == $window->today;

        if (null !== $duty) {
            return new SheetCell(
                day: $day,
                kind: SheetCellKind::Watch,
                shiftKey: $duty['key'],
                shiftLabel: $shifts[$duty['key']]['label'] ?? $duty['key'],
                colour: $shifts[$duty['key']]['colour'] ?? null,
                dutyUuid: $duty['uuid'],
                editedByHand: null !== $mark,
                isToday: $isToday,
            );
        }

        if (null !== $mark) {
            /*
             * THE HAND SAYS WHAT IT DID; THE CYCLE SAYS HOW IT READS.
             *
             * Standing somebody down is an ABSENCE, not a verdict on the
             * seat — so on a day the ring wanted covered it reads as the
             * gap it is, and on a day nothing was asked of it draws
             * nothing at all. "Mark unfilled" is the other thing: the
             * verb IS the decision, so it outlines the day whatever the
             * ring says.
             *
             * `leftOff` is kept on the row rather than collapsed into
             * the kind, because the day an approved absence is ruled to
             * read as "off" that rule needs to know which marks were
             * absences — and that rule belongs on Watches with the
             * others (design note, day-menu flow-b).
             */
            $absence = $mark['leftOff'];

            return new SheetCell(
                day: $day,
                kind: $absence && null === $expects ? SheetCellKind::Off : SheetCellKind::Unfilled,
                shiftKey: $absence ? $expects : null,
                shiftLabel: $absence && null !== $expects ? ($shifts[$expects]['label'] ?? $expects) : null,
                editedByHand: true,
                isToday: $isToday,
            );
        }

        return new SheetCell(
            day: $day,
            kind: null === $expects ? SheetCellKind::Off : SheetCellKind::Unfilled,
            shiftKey: $expects,
            shiftLabel: null === $expects ? null : ($shifts[$expects]['label'] ?? $expects),
            isToday: $isToday,
        );
    }

    /**
     * WHAT THIS STATION EXPECTS OF A SEAT ON A DAY — a closure, because
     * the answer is arithmetic on a ring and the alternative is
     * materialising 952 of them.
     *
     * Null where the station has no pattern or has never been filled: it
     * expects nothing of anybody, which is not the same as expecting
     * nobody.
     *
     * @return (\Closure(\DateTimeImmutable, int): ?string)|null the shift key expected, or null for a day the ring stands the seat down
     */
    private function expectationOf(?StationWatch $watch): ?\Closure
    {
        $pattern = $watch?->getPattern();
        $anchor = $watch?->getPatternFrom();

        if (null === $pattern || null === $anchor) {
            return null;
        }

        $cycle = $pattern->getCycle();

        return static function (\DateTimeImmutable $day, int $seat) use ($cycle, $anchor): ?string {
            $offset = (int) $anchor->diff($day->setTime(0, 0))->format('%r%a') - $seat;
            $position = $cycle->at($offset);

            return $cycle::OFF === $position ? null : $position;
        };
    }

    /**
     * THE RANGERS STATIONED AT EACH STATION, BY NAME — and the order is
     * the seat order, so it has to be stable. A seat that moved when
     * somebody was renamed would slide a whole station's ring.
     *
     * @return array<string, list<array{uuid: string, name: string}>>
     */
    private function peopleByStation(AreaOfInterest $area): array
    {
        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $station = $posting->getStation();
            $person = $posting->getPerson();

            if (null === $station || null === $person) {
                continue;
            }

            $people[(string) $station->getUuidString()][] = [
                'uuid' => (string) $person->getUuidString(),
                'name' => $person->getFullName(),
            ];
        }

        foreach ($people as $uuid => $atStation) {
            usort($atStation, static fn (array $a, array $b): int => [$a['name'], $a['uuid']] <=> [$b['name'], $b['uuid']]);
            $people[$uuid] = $atStation;
        }

        return $people;
    }

    /** @return array<string, StationWatch> by station uuid */
    private function watchesByStation(AreaOfInterest $area): array
    {
        $watches = [];
        foreach ($this->watches->findByArea($area) as $watch) {
            $watches[(string) $watch->getStation()->getUuidString()] = $watch;
        }

        return $watches;
    }

    /**
     * EVERY STANDING DUTY IN THE WINDOW, by ranger and day.
     *
     * ONE PER RANGER PER DAY on the sheet, and the last one read wins: a
     * cell is one bar, and a person on two watches in a day is a fact the
     * day board draws and this grid cannot.
     *
     * @return array<string, array<string, array{key: string, uuid: string}>>
     */
    private function dutiesByPersonAndDay(AreaOfInterest $area, SheetWindow $window): array
    {
        $duties = [];
        foreach ($this->duties->findByAreaBetween($area, $window->from, $window->through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $duties[(string) $duty->getPerson()->getUuidString()][$duty->getOnDay()->format('Y-m-d')] = [
                'key' => $duty->getShiftKey(),
                'uuid' => (string) $duty->getUuid(),
            ];
        }

        return $duties;
    }

    /**
     * EVERY HAND MARK IN THE WINDOW, by ranger and day. A station-wide
     * mark marks every ranger stationed there — a day nobody may touch is
     * a day every cell on it was decided by hand.
     *
     * @return array<string, array<string, array{leftOff: bool}>>
     */
    private function marksByPersonAndDay(AreaOfInterest $area, SheetWindow $window): array
    {
        $wholeStation = [];
        $marks = [];

        foreach ($this->marks->inWindow($area, $window->from, $window->through) as $mark) {
            $person = $mark->getPerson();
            $day = $mark->getOnDay()->format('Y-m-d');

            if (null === $person) {
                $wholeStation[(string) $mark->getStation()->getUuidString()][$day] = ['leftOff' => $mark->isLeftOff()];

                continue;
            }

            $marks[(string) $person->getUuidString()][$day] = ['leftOff' => $mark->isLeftOff()];
        }

        if ([] === $wholeStation) {
            return $marks;
        }

        foreach ($this->peopleByStation($area) as $stationUuid => $people) {
            foreach ($people as $person) {
                foreach ($wholeStation[$stationUuid] ?? [] as $day => $mark) {
                    $marks[$person['uuid']][$day] ??= $mark;
                }
            }
        }

        return $marks;
    }

    /**
     * THE AREA'S SHIFTS, AS THE CELL READS THEM — its own label, and the
     * palette slot it was given when it was created.
     *
     * @return array<string, array{label: string, colour: int}>
     */
    private function shiftFacts(AreaOfInterest $area): array
    {
        $facts = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $facts[$shift->getKey()] = ['label' => $shift->getLabel(), 'colour' => $shift->getColour()];
        }

        return $facts;
    }
}
