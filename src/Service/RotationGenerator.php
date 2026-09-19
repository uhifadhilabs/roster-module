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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Exception\RotationCannotGenerate;
use Uhifadhi\Roster\Model\GenerationRun;
use Uhifadhi\Roster\Model\RotationPlan;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * TURNS A STANDING ROTATION INTO DUTIES — the persistence half of the ruled
 * authoring model. What the ring SAYS is {@see CyclePlanner}'s, pure and unit
 * tested; what becomes a row is here.
 *
 * THE ONE RULE THIS CLASS EXISTS TO KEEP: the generator never overwrites an
 * edited day. A day somebody has touched is marked
 * ({@see \Uhifadhi\Roster\Entity\EditedDay}) and this run steps over it
 * whole — it does not merge, reconcile or "only fill the gaps", because every
 * one of those would silently undo part of a decision a duty officer made
 * while looking at the day.
 *
 * IT IS IDEMPOTENT ON EVERY OTHER DAY. Running it twice over the same window
 * leaves the same duties, because it first clears what THIS rotation put
 * there and then writes the window again. What it never clears is somebody
 * else's work: a duty added by hand carries no rotation, and a duty another
 * rotation generated carries that one.
 *
 * IT WRITES PLANNED DUTIES, NEVER PUBLISHED ONES. Generating is not telling
 * anybody; publishing is a separate act, so a horizon that runs six weeks out
 * does not promise forty-two days of watches to forty-two days of rangers.
 */
final readonly class RotationGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CyclePlanner $planner,
        private ShiftRepository $shifts,
        private RotationPoolMemberRepository $pool,
        private DutyRepository $duties,
        private EditedDayRepository $editedDays,
        private AbsenceRepository $absences,
    ) {
    }

    /**
     * RUN ONE ROTATION OVER A WINDOW, both ends inclusive.
     *
     * @param \DateTimeImmutable|null $through the rotation's own horizon from $from, when not given
     *
     * @throws RotationCannotGenerate when the rotation has no post to stand its watches at
     */
    public function generate(Rotation $rotation, \DateTimeImmutable $from, ?\DateTimeImmutable $through = null): GenerationRun
    {
        $from = $from->setTime(0, 0);
        $through = ($through ?? $from->modify(\sprintf('+%d days', $rotation->getHorizonDays())))->setTime(0, 0);

        if (!$rotation->isActive() || $through < $from) {
            return GenerationRun::nothing($from, $through);
        }

        $station = $this->stationFor($rotation);
        $poolMembers = $this->pool->findOrdered($rotation);

        if ([] === $poolMembers) {
            // A ring with nobody in it produces nothing, and that is not an
            // error: a post whose people have all been taken out of the pool
            // is a post that is short, which every surface already draws.
            $rotation->setGeneratedThrough($through);
            $this->entityManager->flush();

            return GenerationRun::nothing($from, $through);
        }

        $plan = RotationPlan::fromRotation($rotation, $this->shifts->windowsFor($rotation->getArea()));
        $planned = $this->planner->plan($plan, $from, $through);

        $protectedDays = $this->editedDays->protectedDaysBetween($station, $from, $through);
        $protected = array_fill_keys($protectedDays, true);

        $replaced = $this->clearOwnWork($rotation, $from, $through, $protected);

        // Read what is left AFTER the clear, so a hand-made duty is seen and
        // this rotation's own removed rows are not.
        $existing = $this->duties->existingKeysByStationBetween($station, $from, $through);
        $away = $this->absentPersonDays($rotation, $from, $through);

        $created = 0;
        $skippedAbsent = 0;
        $skippedAlreadyThere = 0;

        foreach ($planned as $plannedDuty) {
            $day = $plannedDuty->onDay->format('Y-m-d');

            if (isset($protected[$day])) {
                continue;
            }

            $member = $poolMembers[$plannedDuty->poolPosition] ?? null;
            if (null === $member) {
                continue;
            }

            $person = $member->getPerson();
            $personId = $person->getId();

            if (isset($away[$day][self::personKey($personId)])) {
                ++$skippedAbsent;

                continue;
            }

            $key = DutyRepository::key($plannedDuty->onDay, $plannedDuty->shiftKey, $personId);
            if (isset($existing[$key])) {
                ++$skippedAlreadyThere;

                continue;
            }

            $this->entityManager->persist(
                new Duty($rotation->getArea(), $station, $person, $plannedDuty->shiftKey, $plannedDuty->onDay)
                    ->generatedBy($rotation),
            );
            $existing[$key] = true;
            ++$created;
        }

        $rotation->setGeneratedThrough($through);
        $this->entityManager->flush();

        return new GenerationRun($created, $replaced, $protectedDays, $skippedAbsent, $skippedAlreadyThere, $from, $through);
    }

    /**
     * WHERE THE WATCHES STAND — the post for a per-post ring, the BASE POST
     * for a squad's (ruled 2026-09-20: a squad away on tour is counted at its
     * base).
     *
     * A WELL-FORMED ROTATION ALWAYS HAS ONE, so this refusal is unreachable
     * from the product: {@see Rotation::standAt()} and
     * {@see Rotation::carriedBy()} each require a station, and there is no
     * third way to set the scope. It stands for the half-built row — a
     * hand-written fixture, a direct SQL insert — because a silent "nothing
     * written" there would look exactly like a pool that is all away.
     *
     * @throws RotationCannotGenerate
     */
    private function stationFor(Rotation $rotation): Station
    {
        $station = $rotation->watchStation();

        if (null === $station) {
            throw RotationCannotGenerate::becauseItStandsAtNoPost($rotation);
        }

        return $station;
    }

    /**
     * REMOVE WHAT THIS ROTATION PUT THERE, sparing the days somebody edited.
     *
     * @param array<string, true> $protected
     */
    private function clearOwnWork(Rotation $rotation, \DateTimeImmutable $from, \DateTimeImmutable $through, array $protected): int
    {
        $removed = 0;
        foreach ($this->duties->findGeneratedBy($rotation, $from, $through) as $duty) {
            if (isset($protected[$duty->getOnDay()->format('Y-m-d')])) {
                continue;
            }

            $this->entityManager->remove($duty);
            ++$removed;
        }

        // Flushed before the rewrite, so the unique constraint on (person,
        // station, shift, day) never sees the old row and the new one at once.
        $this->entityManager->flush();

        return $removed;
    }

    /**
     * WHO IS AWAY, DAY BY DAY.
     *
     * Expanded into a per-day lookup rather than asked per planned duty: a
     * six-week horizon over a pool of five is a few hundred questions, and an
     * absence spans days, so the expansion is bounded by the window and not
     * by the roster.
     *
     * @return array<string, array<string, true>> Y-m-d => person key => true
     */
    private function absentPersonDays(Rotation $rotation, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $away = [];
        foreach ($this->absences->findOverlapping($rotation->getArea(), $from, $through) as $absence) {
            $personKey = self::personKey($absence->getPerson()->getId());
            $day = $absence->getStartsOn() > $from ? $absence->getStartsOn() : $from;
            $last = $absence->getEndsOn() < $through ? $absence->getEndsOn() : $through;

            for (; $day <= $last; $day = $day->modify('+1 day')) {
                $away[$day->format('Y-m-d')][$personKey] = true;
            }
        }

        return $away;
    }

    /**
     * A PERSON'S IDENTITY AS AN ARRAY KEY, prefixed on purpose: PHP silently
     * turns a numeric-string key back into an integer, so keying a lookup on
     * a cast id makes its type depend on how the id happens to be spelled.
     * The prefix keeps it a string whatever the account class uses.
     */
    private static function personKey(int|string|null $personId): string
    {
        return 'p'.($personId ?? '?');
    }
}
