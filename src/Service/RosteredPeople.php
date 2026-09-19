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
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;

/**
 * WHO THE CALENDAR'S RANGER PICKER OFFERS — the people this area's
 * rotations actually draw from.
 *
 * THE POOL AND NOT THE PAYROLL. A picker listing every account in the
 * installation would offer hundreds of people with no watches to show; a
 * picker listing the area's postings would offer people no ring draws from.
 * The honest list is whoever a rotation in this area has in its pool,
 * because those are the people whose month has anything in it.
 *
 * DISTINCT, and in a stable order: one person in two rotations is one
 * person, and a picker whose order changed between page loads would move
 * the entry somebody was about to click.
 */
final readonly class RosteredPeople
{
    public function __construct(
        private RotationRepository $rotations,
        private RotationPoolMemberRepository $pool,
    ) {
    }

    /**
     * @return list<array{uuid: string, name: string}> distinct, by name
     */
    public function rosteredIn(AreaOfInterest $area): array
    {
        $people = [];
        foreach ($this->rotations->findByArea($area) as $rotation) {
            foreach ($this->pool->findOrdered($rotation) as $member) {
                $person = $member->getPerson();
                $people[(string) $person->getUuidString()] = [
                    'uuid' => (string) $person->getUuidString(),
                    'name' => $person->getFullName(),
                ];
            }
        }

        $ordered = array_values($people);
        usort($ordered, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $ordered;
    }

    /**
     * WHO THE PAGE IS ABOUT — the one asked for, or the first on the list.
     *
     * A ranger the url names who is not in this area's pools falls back to
     * the first rather than answering an empty month: a mistyped uuid
     * should not look like somebody with no watches.
     *
     * @param list<array{uuid: string, name: string}> $people
     *
     * @return array{uuid: string, name: string}|null
     */
    public function choose(array $people, mixed $asked): ?array
    {
        if ([] === $people) {
            return null;
        }

        if (\is_string($asked)) {
            foreach ($people as $person) {
                if ($person['uuid'] === $asked) {
                    return $person;
                }
            }
        }

        return $people[0];
    }

    /**
     * THE MONTH THE PAGE IS ABOUT. `2026-09`, or this one — a mistyped
     * month in a url is not worth a 500.
     */
    public function monthOf(mixed $asked): YearMonth
    {
        if (\is_string($asked) && 1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $asked, $parts)) {
            return new YearMonth((int) $parts[1], (int) $parts[2]);
        }

        return YearMonth::of(new \DateTimeImmutable('today'));
    }
}
