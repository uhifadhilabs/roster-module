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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Model\DaySlot;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Model\SlotCandidate;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE DAY AS SLOTS TO FILL, and the one write that fills them.
 *
 * A SLOT IS THE QUESTION THE PATTERN ASKS. Every post's watch says how many
 * it expects on each shift; the sheet turns that into "the gate asks two
 * tomorrow night, who?" and shows the question whether or not anybody has
 * answered it. A screen built on duties alone can only show what somebody
 * already decided, which is why the empty slots on this page are drawn as
 * loudly as the filled ones.
 *
 * THE RULES ARE ENFORCED HERE AND NOWHERE ELSE. The template renders what
 * this says; it does not decide anything. Three rules refuse a pick — rest
 * between watches, two watches in one day, and somebody who is away — and
 * one only warns, because nights in a row is a thing a duty officer
 * overrides on a bad week and the others are not.
 *
 * PUBLISHING WRITES DUTIES AND NOTHING ELSE. It does not mark anybody
 * present: presence is derived, later, from what the handsets report
 * against these rows. That is the sentence the page's own subtitle makes,
 * and it is the line this service must not cross.
 *
 * IT REFUSES A BLOCKED PICK ON THE WAY IN. The pill is disabled in the
 * markup, and markup is a suggestion — a form can be posted by anything.
 * So the same rules are asked again here, and a pick that breaks one is
 * dropped with a sentence rather than written.
 */
final readonly class DayPlanService
{
    /** Hours between the end of one watch and the start of the next. */
    public const int REST_HOURS = 11;

    /** Consecutive nights that earn a warning — never a refusal. */
    public const int NIGHTS_IN_A_ROW_WARNS_AT = 3;

    public function __construct(
        private StationWatchRepository $watches,
        private RotationRepository $rotations,
        private RotationPoolMemberRepository $pool,
        private DutyRepository $duties,
        private AbsenceRepository $absences,
        private ShiftVocabularyService $vocabulary,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * EVERY SLOT ON ONE DAY, ordered the way the design reads them: the
     * watches that run in daylight first, then the ones that cross
     * midnight, because that is the order the day itself happens in.
     *
     * @return list<DaySlot>
     */
    public function sheetFor(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        $day = $day->setTime(0, 0);

        // THE VOCABULARY IS ASKED FOR, NOT READ. An area that has never had
        // one is seeded the first time anybody asks, and only the service
        // does that — reading the repository straight would answer "no
        // shifts" on a fresh area, and a sheet with no shifts has no slots
        // at all. The page would then be empty on the first visit and
        // correct on the second, which is the worst kind of bug to find.
        $windows = [];
        $labels = [];
        foreach ($this->vocabulary->openFor($area) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $onTheDay = $this->duties->findByAreaOnDay($area, $day);
        $away = $this->awayOn($area, $day);

        $slots = [];
        foreach ($this->watches->findByArea($area) as $watch) {
            $station = $watch->getStation();

            // HOW MANY THE POST ASKS FOR IS THE RING'S ANSWER, not the
            // watch's: the watch says WHICH shifts a post stands, and the
            // rotation says how many people each of them takes. One is a
            // fact about the post, the other about the pattern it runs.
            $rotation = $this->rotations->findOneForStation($station);
            $slotsPerShift = $rotation?->getSlotsPerShift() ?? [];

            foreach ($watch->getExpects() as $shiftKey) {
                $window = $windows[$shiftKey] ?? null;
                if (null === $window) {
                    // A WATCH ASKING FOR A SHIFT THE AREA NO LONGER NAMES is
                    // a configuration to fix, not a slot to fill.
                    continue;
                }

                $slots[] = new DaySlot(
                    stationUuid: (string) $station->getUuidString(),
                    stationName: (string) $station->getName(),
                    shiftKey: $shiftKey,
                    shiftLabel: $labels[$shiftKey] ?? $shiftKey,
                    asks: max(1, (int) ($slotsPerShift[$shiftKey] ?? 1)),
                    candidates: $this->candidatesFor($area, $station, $shiftKey, $day, $onTheDay, $away, $windows),
                );
            }
        }

        usort($slots, static fn (DaySlot $a, DaySlot $b): int => [
            ($windows[$a->shiftKey] ?? null)?->crossesMidnight() ? 1 : 0, $a->shiftLabel, $a->stationName,
        ] <=> [
            ($windows[$b->shiftKey] ?? null)?->crossesMidnight() ? 1 : 0, $b->shiftLabel, $b->stationName,
        ]);

        return $slots;
    }

    /**
     * WHO THIS SLOT COULD TAKE. The post's own ring, because a slot filled
     * from outside it is a decision about the rotation and belongs in the
     * rotation editor.
     *
     * @param list<Duty>                 $onTheDay
     * @param array<string, string>      $away     person uuid => why, for this day
     * @param array<string, ShiftWindow> $windows
     *
     * @return list<SlotCandidate>
     */
    private function candidatesFor(
        AreaOfInterest $area,
        Station $station,
        string $shiftKey,
        \DateTimeImmutable $day,
        array $onTheDay,
        array $away,
        array $windows,
    ): array {
        $rotation = $this->rotations->findOneForStation($station);
        if (null === $rotation) {
            return [];
        }

        // THE REST RULE IS THE ROTATION'S, not the area's. Each ring
        // carries the rule it runs under, because a park may hold its gate
        // to eleven hours and its office to nothing — and a single
        // area-wide rule would be whichever of the two somebody set last.
        $restRule = $rotation->getRestRule();

        $candidates = [];
        foreach ($this->pool->findOrdered($rotation) as $member) {
            $person = $member->getPerson();
            $uuid = (string) $person->getUuidString();

            $chosen = false;
            $elsewhereToday = null;
            foreach ($onTheDay as $duty) {
                if ((string) $duty->getPerson()->getUuidString() !== $uuid) {
                    continue;
                }

                if ($duty->getShiftKey() === $shiftKey && $duty->getStation()->getId() === $station->getId()) {
                    $chosen = true;

                    continue;
                }

                $elsewhereToday ??= $duty;
            }

            $candidates[] = new SlotCandidate(
                personUuid: $uuid,
                personName: $person->getFullName(),
                chosen: $chosen,
                blockedBy: $chosen ? null : $this->blocks($uuid, $shiftKey, $day, $away, $elsewhereToday, $windows, $restRule, $area),
                warning: $this->warns($area, $uuid, $shiftKey, $day, $windows),
            );
        }

        return $candidates;
    }

    /**
     * THE RULE THAT REFUSES THIS PICK, in the rules card's own words, or
     * null. The order is the card's order, so the reason a pill gives is
     * the first rule a reader would have checked.
     *
     * @param array<string, string>      $away
     * @param array<string, ShiftWindow> $windows
     */
    private function blocks(
        string $personUuid,
        string $shiftKey,
        \DateTimeImmutable $day,
        array $away,
        ?Duty $elsewhereToday,
        array $windows,
        RestRule $restRule,
        AreaOfInterest $area,
    ): ?string {
        if (isset($away[$personUuid])) {
            return $away[$personUuid];
        }

        if (null !== $elsewhereToday) {
            return \sprintf('already on the %s watch at %s', mb_strtolower($elsewhereToday->getShiftKey()), $elsewhereToday->getStation()->getName());
        }

        return $this->restBroken($area, $personUuid, $shiftKey, $day, $windows, $restRule);
    }

    /**
     * THE REST RULE, asked of the watch before this one.
     *
     * THE AREA CHOOSES WHICH RULE IT RUNS, and "no rule" is one of the
     * answers — a park with a handful of rangers may have no choice, and a
     * screen that enforced eleven hours on it would refuse every pick it
     * could make.
     *
     * @param array<string, ShiftWindow> $windows
     */
    private function restBroken(
        AreaOfInterest $area,
        string $personUuid,
        string $shiftKey,
        \DateTimeImmutable $day,
        array $windows,
        RestRule $restRule,
    ): ?string {
        if (RestRule::None === $restRule) {
            return null;
        }

        $window = $windows[$shiftKey] ?? null;
        if (null === $window) {
            return null;
        }

        foreach ($this->duties->findStandingForPersonBetween($area, $personUuid, $day->modify('-1 day'), $day->modify('-1 day')) as $yesterday) {
            $before = $windows[$yesterday->getShiftKey()] ?? null;
            if (null === $before) {
                continue;
            }

            if (RestRule::NoNightThenDay === $restRule && $before->crossesMidnight() && !$window->crossesMidnight()) {
                return 'a night then a day';
            }

            if (RestRule::ElevenHoursBetween === $restRule) {
                // Minutes from yesterday's midnight to the end of that
                // watch, against the same for the start of this one a day
                // later — so a night ending at 06:00 and a day starting at
                // 06:00 is nought hours and not twenty-four.
                $rest = ($window->startsAtMinuteOfDay() + (24 * 60) - $before->endsAtMinuteFromItsDay()) / 60;

                if ($rest < self::REST_HOURS) {
                    return \sprintf('%d h since their last watch', (int) floor($rest));
                }
            }
        }

        return null;
    }

    /**
     * WHAT TO NOTICE ABOUT A PICK WITHOUT REFUSING IT. Nights in a row is
     * the one the design names, and it warns because a duty officer
     * genuinely does override it on a bad week.
     *
     * @param array<string, ShiftWindow> $windows
     */
    private function warns(AreaOfInterest $area, string $personUuid, string $shiftKey, \DateTimeImmutable $day, array $windows): ?string
    {
        if (!($windows[$shiftKey] ?? null)?->crossesMidnight()) {
            return null;
        }

        $run = 0;
        for ($back = $day->modify('-1 day');; $back = $back->modify('-1 day')) {
            $stood = false;
            foreach ($this->duties->findStandingForPersonBetween($area, $personUuid, $back, $back) as $duty) {
                $stood = $stood || (($windows[$duty->getShiftKey()] ?? null)?->crossesMidnight() ?? false);
            }

            if (!$stood) {
                break;
            }

            ++$run;
            if ($run >= self::NIGHTS_IN_A_ROW_WARNS_AT) {
                break;
            }
        }

        return $run + 1 >= self::NIGHTS_IN_A_ROW_WARNS_AT
            ? \sprintf('%d nights in a row', $run + 1)
            : null;
    }

    /**
     * WHO IS AWAY ON THIS DAY, and in what words.
     *
     * @return array<string, string> person uuid => why
     */
    private function awayOn(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        $away = [];
        foreach ($this->absences->findOverlapping($area, $day, $day) as $absence) {
            $away[(string) $absence->getPerson()->getUuidString()] = mb_strtolower($absence->getKind()->label());
        }

        return $away;
    }

    /**
     * PUBLISH THE SHEET: write the duties the picks describe, and remove
     * the ones nobody picked any more.
     *
     * A REMOVED DUTY IS A WATCH SOMEBODY TOOK OFF THE SHEET, so it goes;
     * anything a person or another screen put there stays exactly as it is,
     * because only the slots on this sheet are touched.
     *
     * IT ASKS THE RULES AGAIN. The blocked pills are disabled in the
     * markup and markup is a suggestion — a form can be posted by
     * anything — so a pick that breaks a rule is dropped here with a
     * sentence rather than written.
     *
     * @param array<string, list<string>> $picks "<station uuid>|<shift key>" => person uuids
     *
     * @return list<string> what was refused, in sentences, empty where nothing was
     */
    public function publish(AreaOfInterest $area, \DateTimeImmutable $day, array $picks): array
    {
        $day = $day->setTime(0, 0);
        $refused = [];

        foreach ($this->sheetFor($area, $day) as $slot) {
            $wanted = $picks[$slot->stationUuid.'|'.$slot->shiftKey] ?? [];

            foreach ($slot->candidates as $candidate) {
                $pick = \in_array($candidate->personUuid, $wanted, true);

                if ($pick && !$candidate->chosen) {
                    if ($candidate->isBlocked()) {
                        $refused[] = \sprintf('%s cannot take the %s watch at %s — %s.', $candidate->personName, mb_strtolower($slot->shiftLabel), $slot->stationName, $candidate->blockedBy);

                        continue;
                    }

                    $this->put($area, $slot, $candidate->personUuid, $day);
                }

                if (!$pick && $candidate->chosen) {
                    $this->take($area, $slot, $candidate->personUuid, $day);
                }
            }
        }

        $this->entityManager->flush();

        return $refused;
    }

    private function put(AreaOfInterest $area, DaySlot $slot, string $personUuid, \DateTimeImmutable $day): void
    {
        $rotation = null;
        $station = null;
        foreach ($this->watches->findByArea($area) as $watch) {
            if ((string) $watch->getStation()->getUuidString() === $slot->stationUuid) {
                $station = $watch->getStation();
                $rotation = $this->rotations->findOneForStation($station);

                break;
            }
        }

        if (null === $station || null === $rotation) {
            return;
        }

        foreach ($this->pool->findOrdered($rotation) as $member) {
            if ((string) $member->getPerson()->getUuidString() === $personUuid) {
                $this->entityManager->persist(new Duty($area, $station, $member->getPerson(), $slot->shiftKey, $day));

                return;
            }
        }
    }

    private function take(AreaOfInterest $area, DaySlot $slot, string $personUuid, \DateTimeImmutable $day): void
    {
        foreach ($this->duties->findByAreaOnDay($area, $day) as $duty) {
            if ((string) $duty->getPerson()->getUuidString() === $personUuid
                && $duty->getShiftKey() === $slot->shiftKey
                && (string) $duty->getStation()->getUuidString() === $slot->stationUuid) {
                $this->entityManager->remove($duty);
            }
        }
    }

    /**
     * WHO COULD STAND A WATCH THAT NOBODY IS ON — the design's "who is free
     * for the night watch", read over whichever shifts are short.
     *
     * @param list<DaySlot> $sheet
     *
     * @return list<SlotCandidate>
     */
    public static function whoIsFree(array $sheet): array
    {
        $free = [];
        foreach ($sheet as $slot) {
            if (!$slot->isShort()) {
                continue;
            }

            foreach ($slot->candidates as $candidate) {
                if (!$candidate->chosen && !isset($free[$candidate->personUuid])) {
                    $free[$candidate->personUuid] = $candidate;
                }
            }
        }

        return array_values($free);
    }

    /** @return list<UserInterface> */
    public function peopleAwayOn(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $people = [];
        foreach ($this->absences->findOverlapping($area, $from, $through) as $absence) {
            $people[] = $absence->getPerson();
        }

        return $people;
    }
}
