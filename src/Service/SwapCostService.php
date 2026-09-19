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

use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Model\SwapCost;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * WHAT A TRADE COSTS, worked out before anybody is asked.
 *
 * EVERY CHECK IS STATED, NONE IS ENFORCED. A duty officer may knowingly send
 * an offer that breaks the rest rule — a gate that would otherwise stand
 * empty is sometimes the worse outcome — and what the page must never do is
 * send one QUIETLY. So this answers with sentences and the card prints them;
 * nothing here refuses.
 *
 * THE REST RULE IS READ FROM THE POST'S OWN ROTATION, not from a constant:
 * the same trade is fine at a post that runs no rule and wrong at one that
 * demands eleven hours, and a single answer for both would be wrong half the
 * time.
 */
final readonly class SwapCostService
{
    public function __construct(
        private DutyRepository $duties,
        private ShiftRepository $shifts,
        private RotationRepository $rotations,
        private AbsenceRepository $absences,
    ) {
    }

    /**
     * THE CHECKS FOR ONE TRADE: what the person taking it worked last, and
     * whether they are away.
     */
    public function of(Duty $duty, UserInterface $taking): SwapCost
    {
        $checks = [];

        $windows = [];
        foreach ($this->shifts->findByArea($duty->getArea()) as $shift) {
            $windows[$shift->getKey()] = ShiftWindow::of($shift->getKey(), $shift->getStartsAt(), $shift->getEndsAt());
        }

        $checks[] = $this->restCheck($duty, $taking, $windows);
        $checks[] = $this->awayCheck($duty, $taking);
        $checks[] = $this->clashCheck($duty, $taking);

        // THE ONE CHECK THAT NEVER HOLDS UNTIL THE HANDSET ANSWERS. It is
        // listed rather than implied, because "send the offer" and "the
        // watch has changed hands" are two different things and a card that
        // did not say so would let somebody walk away believing the second.
        $checks[] = [
            'label' => 'Needs',
            'holds' => false,
            'detail' => \sprintf('%s’s acceptance, on the handset', $taking->getFullName()),
        ];

        return new SwapCost($checks);
    }

    /**
     * @param array<string, ShiftWindow> $windows
     *
     * @return array{label: string, holds: bool, detail: string}
     */
    private function restCheck(Duty $duty, UserInterface $taking, array $windows): array
    {
        $rule = $this->rotations->findOneForStation($duty->getStation())?->getRestRule();
        if (null === $rule || 'none' === $rule->value) {
            return ['label' => 'Rest rule', 'holds' => true, 'detail' => 'this post runs no rest rule'];
        }

        $yesterday = $duty->getOnDay()->modify('-1 day');
        $before = $this->duties->findStandingForPersonBetween(
            $duty->getArea(),
            (string) $taking->getUuidString(),
            $yesterday,
            $yesterday,
        );

        if ([] === $before) {
            return ['label' => 'Rest rule', 'holds' => true, 'detail' => \sprintf('holds — nothing the day before under "%s"', $rule->label())];
        }

        $previous = $windows[$before[0]->getShiftKey()] ?? null;
        $current = $windows[$duty->getShiftKey()] ?? null;

        if (null === $previous || null === $current) {
            return ['label' => 'Rest rule', 'holds' => true, 'detail' => 'unknown — a shift the area cannot describe'];
        }

        $gap = (($current->startsAtMinuteOfDay() + (24 * 60)) - $previous->endsAtMinuteFromItsDay()) / 60;
        $holds = 'no_night_then_day' === $rule->value
            ? !($previous->crossesMidnight() && !$current->crossesMidnight())
            : $gap >= 11.0;

        return [
            'label' => 'Rest rule',
            'holds' => $holds,
            'detail' => \sprintf(
                '%s — %s the day before, %s h between watches',
                $holds ? 'holds' : 'BROKEN',
                $before[0]->getShiftKey(),
                number_format($gap, 1),
            ),
        ];
    }

    /**
     * @return array{label: string, holds: bool, detail: string}
     */
    private function awayCheck(Duty $duty, UserInterface $taking): array
    {
        foreach ($this->absences->findOverlapping($duty->getArea(), $duty->getOnDay(), $duty->getOnDay()) as $absence) {
            if ($absence->getPerson()->getId() === $taking->getId()) {
                return [
                    'label' => 'Away',
                    'holds' => false,
                    'detail' => \sprintf('%s is recorded %s that day', $taking->getFullName(), mb_strtolower($absence->getKind()->label())),
                ];
            }
        }

        return ['label' => 'Away', 'holds' => true, 'detail' => 'nothing recorded against that day'];
    }

    /**
     * ALREADY ON SOMETHING THAT DAY. Not a block — a day holds any number of
     * watches — but a fact somebody sending the offer should see.
     *
     * @return array{label: string, holds: bool, detail: string}
     */
    private function clashCheck(Duty $duty, UserInterface $taking): array
    {
        $same = $this->duties->findStandingForPersonBetween(
            $duty->getArea(),
            (string) $taking->getUuidString(),
            $duty->getOnDay(),
            $duty->getOnDay(),
        );

        if ([] === $same) {
            return ['label' => 'That day', 'holds' => true, 'detail' => 'nothing else rostered'];
        }

        return [
            'label' => 'That day',
            'holds' => false,
            'detail' => \sprintf('already on %d other watch%s that day', \count($same), 1 === \count($same) ? '' : 'es'),
        ];
    }
}
