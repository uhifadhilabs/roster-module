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

namespace Uhifadhi\Roster\Module;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Roster\Watch;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THIS MODULE'S ANSWER TO "WHAT IS THIS PERSON ROSTERED FOR" — the one
 * question the area asks the roster, on behalf of a handset reading its
 * month.
 *
 * A DAY WITH NO WATCH IS A REST DAY, and it is answered by SAYING NOTHING.
 * There is no rest value in the contract and this returns no row for such a
 * day, which is what makes the phone draw no row, no dot and no reminder.
 * That falls out of the model rather than being arranged: the ring's off day
 * generates no duty, so there is nothing here to leave out.
 *
 * A CANCELLED DUTY IS NOT A WATCH. It was called off, and the phone must not
 * remind somebody to stand it; the row stays in the database because a watch
 * that was cancelled is a fact somebody may have to read back, and it stays
 * out of this answer because nobody is due.
 *
 * READ-ONLY AND PER PERSON, exactly as the contract says. This module holds
 * swaps, absences, rotations and holes, and none of that is the question:
 * the phone needs a month of watches.
 *
 * THE INSTANTS ARE BUILT FROM THE SHIFT WINDOW, and a window that crosses
 * midnight ends on the FOLLOWING day — which is why a night watch's
 * `localDate` and its `endsAt` disagree about the date, on purpose. The
 * contract is explicit that the day is stated rather than derived for
 * exactly this case.
 */
final readonly class RosterWatches implements WatchProviderInterface
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private DutyRepository $duties,
        private ShiftRepository $shifts,
    ) {
    }

    public function watchesFor(string $areaUuid, string $personUuid, string $from, string $to): array
    {
        $area = $this->areaByUuid($areaUuid);
        $fromDay = self::day($from);
        $toDay = self::day($to);

        if (null === $area || null === $fromDay || null === $toDay || $toDay < $fromDay) {
            return [];
        }

        // THE VOCABULARY ONCE, not per duty: a month of watches is thirty
        // lookups of a list that cannot change inside one answer.
        $windows = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $windows[$shift->getKey()] = $shift;
        }

        $watches = [];
        foreach ($this->duties->findStandingForPersonBetween($area, $personUuid, $fromDay, $toDay) as $duty) {
            $watch = $this->watchFor($duty, $windows[$duty->getShiftKey()] ?? null);
            if (null !== $watch) {
                $watches[] = $watch;
            }
        }

        return $watches;
    }

    /**
     * ONE DUTY AS THE CONTRACT'S WATCH.
     *
     * A SHIFT THE AREA'S LIST CANNOT DESCRIBE ANY MORE answers null rather
     * than guessing a window. It can only happen to a duty whose shift row
     * was deleted out from under it — which the product does not do, because
     * a shift is closed and never deleted — and inventing 00:00 to 00:00
     * would put a watch on a phone at midnight.
     */
    private function watchFor(Duty $duty, ?Shift $shift): ?Watch
    {
        if (null === $shift) {
            return null;
        }

        $day = $duty->getOnDay();
        $startsAt = self::at($day, $shift->getStartsAt());
        $endsAt = self::at($day, $shift->getEndsAt());

        if (null === $startsAt || null === $endsAt) {
            return null;
        }

        // A window that crosses midnight ends the next morning. Compared
        // rather than asking the shift, because the two must agree and this
        // is the comparison that decides the instant.
        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        return new Watch(
            localDate: $day->format('Y-m-d'),
            startsAt: $startsAt,
            endsAt: $endsAt,
            stationUuid: $duty->getStation()->getUuidString(),
            label: $shift->getLabel(),
        );
    }

    private function areaByUuid(string $areaUuid): ?AreaOfInterest
    {
        if (!Uuid::isValid($areaUuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($areaUuid)]);
    }

    /**
     * A DATE OUT OF A STRING THE CALLER CHOSE. Null rather than an
     * exception: the contract takes `2026-09-01` and a caller that sends
     * something else gets an empty month, not a 500 on somebody's phone.
     */
    private static function day(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false === $parsed ? null : $parsed;
    }

    private static function at(\DateTimeImmutable $day, string $clock): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $clock, $parts)) {
            return null;
        }

        return $day->setTime((int) $parts[1], (int) $parts[2]);
    }
}
