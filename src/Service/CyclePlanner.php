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

use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\PlannedDuty;
use Uhifadhi\Roster\Model\RotationPlan;
use Uhifadhi\Roster\Model\ShiftWindow;

/**
 * WHAT THE RING SAYS, FOR A RANGE OF DAYS — the whole of the rotation
 * algebra, and not one line of it touches a database.
 *
 * It is separated from {@see RotationGenerator} on purpose. Turning a ring
 * into a month is the part that is easy to get subtly wrong — the wrap at the
 * anchor, the person who enters one day later than the last, a night watch
 * that collides with the next morning — and a mistake in any of it is
 * invisible on a screen until somebody is rostered onto a day after their
 * night. So it is a pure calculation with its own unit tests, and the part
 * that needs people, stations and rows is the other class.
 *
 * THE REST RULE PRODUCES A HOLE, NEVER AN ILLEGAL WATCH. That is ruled, and
 * it is the one place this class removes something the ring asked for: the
 * duty is dropped, the watch comes up short against what the station expects,
 * and the shortfall is visible on every surface in the module.
 */
final readonly class CyclePlanner
{
    /**
     * EVERY WATCH THE RING PRODUCES BETWEEN TWO DAYS, both ends inclusive.
     *
     * @return list<PlannedDuty> in day order, then pool order
     */
    public function plan(RotationPlan $plan, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $from = $from->setTime(0, 0);
        $through = $through->setTime(0, 0);

        if ($through < $from || $plan->poolSize < 1) {
            return [];
        }

        /*
         * THE WARM-UP, and it is not optional. A rest rule asks what a person
         * worked YESTERDAY, and the honest answer has to be what they
         * ACTUALLY worked — which may itself have been dropped by the rule the
         * day before. So the scan starts one whole ring before the range and
         * throws that part away: by the time it reaches `from` the chain has
         * settled, and asking for one week gives the same answer as asking for
         * the month that contains it.
         */
        $scanFrom = $from->modify(\sprintf('-%d days', $plan->cycle->length()));

        $planned = [];
        for ($position = 0; $position < $plan->poolSize; ++$position) {
            foreach ($this->planOnePerson($plan, $position, $scanFrom, $through) as $duty) {
                if ($duty->onDay >= $from) {
                    $planned[] = $duty;
                }
            }
        }

        usort($planned, static fn (PlannedDuty $a, PlannedDuty $b): int => [$a->onDay, $a->poolPosition] <=> [$b->onDay, $b->poolPosition]);

        return $planned;
    }

    /**
     * ONE PERSON'S CHAIN, walked forwards a day at a time, because that is the
     * only order in which "what did they work yesterday" has an answer.
     *
     * @return list<PlannedDuty>
     */
    private function planOnePerson(RotationPlan $plan, int $position, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $planned = [];
        $previous = null;
        $previousDay = null;

        for ($day = $from; $day <= $through; $day = $day->modify('+1 day')) {
            // THE POSITION IN THE POOL IS SUBTRACTED, not added: whoever
            // stands second enters the ring one day LATER than whoever stands
            // first, so on any given date they are one day earlier in it.
            $slot = $plan->cycle->at($plan->offsetFor($day) - $position);

            if (Cycle::OFF === $slot || $plan->standsDownOn($day)) {
                $previous = null;
                $previousDay = null;

                continue;
            }

            $window = $plan->windowFor($slot);

            if (null !== $previous && null !== $previousDay && !$this->rested($plan->restRule, $previous, $previousDay, $window, $day)) {
                // Dropped: the ring asked for a watch the rest rule forbids,
                // so the post comes up short and nobody works two in a row.
                // They DID rest, so the chain restarts from here.
                $previous = null;
                $previousDay = null;

                continue;
            }

            $planned[] = new PlannedDuty($position, $slot, $day);
            $previous = $window;
            $previousDay = $day;
        }

        return $planned;
    }

    /**
     * WHETHER THE GAP BETWEEN THE LAST WATCH AND THIS ONE SATISFIES THE RULE.
     *
     * A window whose shift the area cannot describe (a key no shift answers
     * to) is UNKNOWN, and an unknown gap passes: refusing to roster somebody
     * because a vocabulary row was renamed would turn a configuration mistake
     * into an empty park.
     */
    private function rested(RestRule $rule, ShiftWindow $previous, \DateTimeImmutable $previousDay, ?ShiftWindow $current, \DateTimeImmutable $day): bool
    {
        if (RestRule::None === $rule || null === $current) {
            return true;
        }

        if (RestRule::NoNightThenDay === $rule) {
            // A watch that runs past midnight ends on the morning of the next
            // day; anything standing that next day that does not itself run
            // past midnight is the "day after a night" the rule names.
            return !($previous->crossesMidnight() && $this->isTheNextDay($previousDay, $day) && !$current->crossesMidnight());
        }

        return $this->hoursBetween($previous, $previousDay, $current, $day) >= 11.0;
    }

    private function isTheNextDay(\DateTimeImmutable $previousDay, \DateTimeImmutable $day): bool
    {
        return $previousDay->modify('+1 day')->format('Y-m-d') === $day->format('Y-m-d');
    }

    /**
     * The gap in hours between the end of the previous watch and the start of
     * this one. Both are anchored to the midnight of the day they BELONG to,
     * which is what makes a night watch's 06:00 end land on the right side of
     * the next morning's 06:00 start.
     */
    private function hoursBetween(ShiftWindow $previous, \DateTimeImmutable $previousDay, ShiftWindow $current, \DateTimeImmutable $day): float
    {
        $daysApart = (int) $previousDay->diff($day)->days;

        $previousEnd = $previous->endsAtMinuteFromItsDay();
        $currentStart = ($daysApart * 24 * 60) + $current->startsAtMinuteOfDay();

        return ($currentStart - $previousEnd) / 60.0;
    }
}
