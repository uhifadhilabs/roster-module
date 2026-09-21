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
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE AREA'S ONE LIST OF NAMED SHIFTS — seeded on first ask, renamed freely,
 * CLOSED AND NEVER DELETED.
 *
 * "One list for the whole area" is ruled: every rotation, every rota cell and
 * every day-board block reads from it, so a fifth shift is a fifth chip
 * everywhere and nothing has to be redrawn.
 *
 * SEEDED ON FIRST ASK, for the reason the settings row is created on first
 * ask: nobody should have to invent a day shift before they can write a
 * rotation. The bundle's `roster.shifts` config is the seed; after that the
 * list is the park's and the config is never read again.
 *
 * A SHIFT IN USE CANNOT BE DELETED, ONLY CLOSED — and the check is against
 * DUTIES, not against rotations, because the duties are what keep the word
 * that described a past month. A closed shift stops being offered and keeps
 * every row that names it.
 */
final readonly class ShiftVocabularyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShiftRepository $shifts,
        private DutyRepository $duties,
        /**
         * The vocabulary a new area is seeded with.
         *
         * @var list<array{key: string, label: string, start: string, end: string, colour?: int}>
         */
        private array $seed,
    ) {
    }

    /**
     * THIS AREA'S LIST, closed shifts included — the Settings section dims a
     * closed row, it does not hide it.
     *
     * @return list<Shift>
     */
    public function forArea(AreaOfInterest $area): array
    {
        $shifts = $this->shifts->findByArea($area);

        if ([] === $shifts) {
            $shifts = $this->seed($area);
        }

        return $shifts;
    }

    /**
     * The shifts a rotation may still be built out of.
     *
     * @return list<Shift>
     */
    public function openFor(AreaOfInterest $area): array
    {
        return array_values(array_filter($this->forArea($area), static fn (Shift $shift): bool => $shift->isOpen()));
    }

    /**
     * ADD A SHIFT. The key is issued once and never edited, so it is settled
     * here and nowhere else.
     *
     * @throws \InvalidArgumentException when the key is reserved, malformed or already in the list
     */
    public function add(AreaOfInterest $area, string $key, string $label, string $startsAt, string $endsAt): Shift
    {
        $this->guardKey($key);

        // THE VOCABULARY IS READ BEFORE THE KEY IS CHECKED, because reading
        // it is what SEEDS an area that has never had one — and a key that
        // does not clash with an empty vocabulary may well clash with the
        // four defaults that reading it just created. Asking first and
        // checking after was a unique-constraint crash instead of the
        // sentence below, for the one case most likely to hit it: adding
        // "day" to an area nobody had configured yet.
        $position = \count($this->forArea($area));

        if (null !== $this->shifts->findOneByKey($area, $key)) {
            throw new \InvalidArgumentException(\sprintf('This area already has a shift called "%s". A duty stores the key, so two of them would make a stored row ambiguous about the window it was stood in.', $key));
        }

        $shift = new Shift($area, $key, $label, $startsAt, $endsAt, $position);
        $this->entityManager->persist($shift);
        $this->entityManager->flush();

        return $shift;
    }

    /** A label is what people read, so it may be changed freely. */
    public function rename(Shift $shift, string $label): Shift
    {
        $shift->rename($label);
        $this->entityManager->flush();

        return $shift;
    }

    public function moveWindow(Shift $shift, string $startsAt, string $endsAt): Shift
    {
        $shift->moveWindow($startsAt, $endsAt);
        $this->entityManager->flush();

        return $shift;
    }

    /**
     * CLOSE A SHIFT. It keeps every duty that names it and stops being
     * offered, which is what lets a past month keep the words that describe
     * it.
     */
    public function close(Shift $shift, \DateTimeImmutable $on): Shift
    {
        $shift->close($on);
        $this->entityManager->flush();

        return $shift;
    }

    /**
     * WHETHER A SHIFT HAS EVER BEEN STOOD. What the Settings section asks
     * before it offers anything other than "close" — and the answer is about
     * DUTIES, because those are the rows that would lose their word.
     */
    public function isInUse(Shift $shift): bool
    {
        return [] !== $this->duties->findByAreaAndShift($shift->getArea(), $shift->getKey(), 1);
    }

    /**
     * A SEEDED SHIFT OPENS ON ITS OWN PALETTE SLOT. Four shifts sharing one
     * slot paint a whole fortnight in one hue, which is the sheet's hardest
     * thing to read; the slot is the shift's from then on, and the Configure
     * page is the only place it changes.
     *
     * @return list<Shift>
     */
    private function seed(AreaOfInterest $area): array
    {
        $shifts = [];
        foreach ($this->seed as $position => $definition) {
            $shift = new Shift($area, $definition['key'], $definition['label'], $definition['start'], $definition['end'], $position, $definition['colour'] ?? Shift::FIRST_SLOT);
            $this->entityManager->persist($shift);
            $shifts[] = $shift;
        }

        $this->entityManager->flush();

        return $shifts;
    }

    private function guardKey(string $key): void
    {
        if (Cycle::OFF === $key) {
            throw new \InvalidArgumentException('"off" is reserved: a rotation\'s ring spells a stood-down day that way, so a shift of that name would make every stored cycle ambiguous.');
        }

        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A shift key is lowercase letters, digits and underscores, starting with a letter; got "%s". It is stored on every duty, so it is an identifier and not a label.', $key));
        }
    }
}
