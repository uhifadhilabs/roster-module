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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Model\RotationDraft;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;

/**
 * APPLYING A DRAFT TO A ROTATION — the one write the cycle editor makes.
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

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RotationPoolMemberRepository $pool,
    ) {
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
