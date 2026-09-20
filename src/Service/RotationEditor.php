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
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\RotationDraft;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;

/**
 * DECLARING A ROTATION, AND APPLYING A DRAFT TO ONE — the two writes that
 * put a pattern on this module's books.
 *
 * DECLARING IS ITS OWN ACT. A post with a watch still generates nothing
 * until somebody says what ring it runs, so "New rotation" is a door and
 * not a side effect of putting the post on the books: the area may register
 * twelve posts and mean to pattern four of them.
 *
 * ONE SAVE, ONE WRITE. The editor changes the ring, the counts and the pool
 * many times in a sitting and writes none of it until Save; this applies the
 * whole draft at once, so a rotation is never left half-changed.
 *
 * THE POOL IS REWRITTEN IN THE ORDER THE DRAFT GIVES, because the order IS
 * the plan: each person enters the ring one day later than the last, so
 * moving somebody from third to first re-plans the post. Members who left
 * the draft are removed and the rest are renumbered from zero — there is no
 * such thing as a pool with a gap at position 2.
 *
 * IT DOES NOT GENERATE. Saving the rotation and running the generator are
 * two acts, and the design says so: "Save generates from tomorrow" is the
 * caller's sequence, not this method's side effect. Keeping them apart is
 * what lets somebody correct a typo in a ring without rewriting six weeks of
 * duties on the spot.
 */
final readonly class RotationEditor
{
    /**
     * THE THREE HORIZONS THE EDITOR OFFERS, each as a number of days.
     *
     * A PRESET IS A WAY OF CHOOSING A NUMBER and the number is what is
     * stored: "end of the year" kept as a preset would mean something
     * different every January, and a rotation's horizon would quietly
     * shorten as December approached.
     *
     * @var array<string, int>
     */
    public const array HORIZONS = [
        'Six weeks ahead' => 42,
        'Three months ahead' => 92,
        'Six months ahead' => 183,
    ];

    /**
     * WHAT A NEWLY DECLARED ROTATION GENERATES TO — the shortest horizon the
     * editor offers, so a ring nobody has looked at yet writes six weeks
     * rather than half a year of duties.
     */
    public const int STARTING_HORIZON_DAYS = 42;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RotationPoolMemberRepository $pool,
        private RotationRepository $rotations,
    ) {
    }

    /**
     * DECLARE A ROTATION FOR A POST — the door "New rotation" opens.
     *
     * THE RING COMES FROM THE POST'S OWN WATCH. A preset is a shape and the
     * watches the post declares are what fills it, so a park that stands an
     * `office` and a `radio night` gets a ring in its own words rather than
     * one written in somebody else's.
     *
     * AND THE POOL IS THE PEOPLE POSTED THERE, in the order the area posted
     * them. That is the honest first answer — the ring draws on whoever
     * stands at the post — and every one of them can be taken out again in
     * the editor, which is where a pool is decided.
     *
     * ONE RING PER POST. A second declaration answers with the first rather
     * than quietly giving a post two patterns that would both generate.
     *
     * @param list<string>        $expects the post's own shift keys
     * @param list<UserInterface> $pool    the people posted at that post, in order
     *
     * @throws \InvalidArgumentException when the post declares no watch for a ring to repeat
     */
    public function declareForPost(Station $station, array $expects, RotationPreset $preset, array $pool): Rotation
    {
        $standing = $this->rotations->findOneForStation($station);
        if (null !== $standing) {
            return $standing;
        }

        $area = $station->getArea() ?? throw new \InvalidArgumentException('A post always belongs to an area; this one does not, so there is no roster to declare a rotation in.');

        $rotation = $this->declare($area, $preset, $expects)->standAt($station);

        return $this->persist($rotation, $pool);
    }

    /**
     * DECLARE A ROTATION A SQUAD CARRIES, filed at the post it is based at.
     *
     * THE BASE IS REQUIRED and it is not a formality: a duty is one watch at
     * one station on one day, so a team's watches have to be filed
     * somewhere, and every count in the product is keyed by station.
     *
     * @param list<string>        $expects the base post's shift keys
     * @param list<UserInterface> $pool
     *
     * @throws \InvalidArgumentException when the squad is unnamed or the base post declares no watch
     */
    public function declareForTeam(string $teamName, Station $baseStation, array $expects, RotationPreset $preset, array $pool): Rotation
    {
        $teamName = trim($teamName);
        if ('' === $teamName) {
            throw new \InvalidArgumentException('A squad’s rotation is read by its name on every register in this module, so it needs one.');
        }

        $area = $baseStation->getArea() ?? throw new \InvalidArgumentException('A post always belongs to an area; this one does not, so there is no roster to declare a rotation in.');

        $rotation = $this->declare($area, $preset, $expects)->carriedBy($teamName, $baseStation);

        return $this->persist($rotation, $pool);
    }

    /**
     * THE PART BOTH SHAPES SHARE — the ring, the counts and the two numbers
     * a new rotation starts with.
     *
     * DAY ONE IS TODAY and the horizon is the shortest one offered, both
     * because they are the answers somebody declaring a rotation is least
     * surprised by: the ring starts now, and it writes six weeks rather
     * than half a year before anybody has looked at it.
     *
     * @param list<string> $expects
     */
    private function declare(AreaOfInterest $area, RotationPreset $preset, array $expects): Rotation
    {
        return new Rotation(
            $area,
            RotationScope::Post,
            $preset->ringFor($expects),
            new \DateTimeImmutable('today'),
            $preset->slotsFor($expects),
            self::STARTING_HORIZON_DAYS,
        );
    }

    /**
     * @param list<UserInterface> $pool
     */
    private function persist(Rotation $rotation, array $pool): Rotation
    {
        $this->entityManager->persist($rotation);

        foreach ($pool as $position => $person) {
            $this->entityManager->persist(new RotationPoolMember($rotation, $person, $position));
        }

        $this->entityManager->flush();

        return $rotation;
    }

    /**
     * @param array<string, UserInterface> $candidates the people this draft may draw from, keyed by uuid
     *
     * @throws \InvalidArgumentException when the draft names somebody the area cannot offer
     */
    public function apply(Rotation $rotation, RotationDraft $draft, array $candidates): Rotation
    {
        $rotation
            ->setCycle($draft->cycle())
            ->setSlotsPerShift($draft->slotsPerShift)
            ->setStandDownWeekdays($draft->standDownWeekdays)
            ->setRestRule($draft->restRule)
            ->setHorizonDays($draft->horizonDays)
            ->setAnchoredOn($draft->anchoredOn);

        $this->rewritePool($rotation, $draft, $candidates);

        $this->entityManager->flush();

        return $rotation;
    }

    /**
     * THE POOL, IN THE DRAFT'S ORDER.
     *
     * Existing members are kept and renumbered rather than deleted and
     * recreated: a member row is what a swap or a later reading may point
     * at, and churning the identity of a row whose only change is its
     * position would be losing something for nothing.
     *
     * @param array<string, UserInterface> $candidates
     */
    private function rewritePool(Rotation $rotation, RotationDraft $draft, array $candidates): void
    {
        $existing = [];
        foreach ($this->pool->findOrdered($rotation) as $member) {
            $existing[(string) $member->getPerson()->getUuidString()] = $member;
        }

        $keep = [];
        foreach ($draft->poolUuids as $position => $uuid) {
            $person = $candidates[$uuid] ?? null;
            if (null === $person) {
                throw new \InvalidArgumentException(\sprintf('The pool names somebody this area does not offer (%s). A ring can only draw from people the area has.', $uuid));
            }

            $member = $existing[$uuid] ?? null;
            if (null === $member) {
                $member = new RotationPoolMember($rotation, $person, $position);
                $this->entityManager->persist($member);
            } else {
                $member->setPosition($position);
            }

            $keep[$uuid] = true;
        }

        // TAKEN OUT OF THE RING, not out of the park. Removing somebody from
        // a pool stops the ring drawing on them; it changes no posting and no
        // duty already written.
        foreach ($existing as $uuid => $member) {
            if (!isset($keep[$uuid])) {
                $rotation->removeFromPool($member);
                $this->entityManager->remove($member);
            }
        }
    }
}
