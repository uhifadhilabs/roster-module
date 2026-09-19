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
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Contracts\Entity\UserInterface;
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
        private PostingRepository $postings,
    ) {
    }

    /**
     * @return list<array{uuid: string, name: string}> distinct, by name
     */
    public function rosteredIn(AreaOfInterest $area): array
    {
        $people = [];
        foreach ($this->byUuid($area) as $uuid => $person) {
            $people[$uuid] = ['uuid' => $uuid, 'name' => $person->getFullName()];
        }

        $ordered = array_values($people);
        usort($ordered, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $ordered;
    }

    /**
     * EVERYBODY A ROTATION IN THIS AREA MAY DRAW FROM, keyed by uuid — what
     * the editor checks a submitted pool against.
     *
     * IT IS THE AREA'S PEOPLE AND NOT THE INSTALLATION'S. A pool that could
     * name anybody with an account would let one park's ring draw on another
     * park's rangers, and nothing on either page would say so.
     *
     * @return array<string, UserInterface>
     */
    public function byUuid(AreaOfInterest $area): array
    {
        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $person = $posting->getPerson();
            if (null !== $person) {
                $people[(string) $person->getUuidString()] = $person;
            }
        }

        // Anybody already in a ring stays offerable even if their posting
        // moved: taking somebody out of a pool is a decision, not something
        // that should happen behind a duty officer's back.
        foreach ($this->rotations->findByArea($area) as $rotation) {
            foreach ($this->pool->findOrdered($rotation) as $member) {
                $people[(string) $member->getPerson()->getUuidString()] = $member->getPerson();
            }
        }

        return $people;
    }

    /**
     * EVERYBODY A SWAP MAY BE OFFERED TO — the same set a ring may draw
     * from, as objects.
     *
     * @return list<UserInterface>
     */
    public function findCandidates(AreaOfInterest $area): array
    {
        return array_values($this->byUuid($area));
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
     * WHAT TEAM CALLS THIS PERSON — their position's name, or null where
     * they hold none.
     *
     * IT IS READ, NEVER STORED HERE. A role is team's fact about a person
     * and this module only prints it beside their month; a roster that kept
     * its own copy would be a second org chart going quietly out of date.
     */
    public function roleOf(AreaOfInterest $area, string $personUuid): ?string
    {
        $person = $this->byUuid($area)[$personUuid] ?? null;

        return $person instanceof User ? $person->getPosition()?->getName() : null;
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
