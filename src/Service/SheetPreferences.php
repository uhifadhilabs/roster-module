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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\SheetPreference;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Repository\SheetPreferenceRepository;

/**
 * HOW THIS PERSON LIKES THE SHEET, READ AND WRITTEN.
 *
 * NOBODY SIGNED IN IS A REAL CASE and it answers the standard rather
 * than refusing: a preference is a convenience, and a page that would
 * not render without one would be a page held hostage by a nicety.
 */
final readonly class SheetPreferences
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SheetPreferenceRepository $preferences,
    ) {
    }

    public function weeksFor(?UserInterface $person, AreaOfInterest $area): int
    {
        return $this->of($person, $area)?->getWeeks() ?? SheetWindow::DEFAULT_WEEKS;
    }

    /** @return list<string> the stations this person keeps folded */
    public function foldedFor(?UserInterface $person, AreaOfInterest $area): array
    {
        return $this->of($person, $area)?->getFolded() ?? [];
    }

    public function rememberWeeks(?UserInterface $person, AreaOfInterest $area, int $weeks): void
    {
        $row = $this->write($person, $area);
        $row?->readWeeks($weeks);

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $folded
     */
    public function rememberFolds(?UserInterface $person, AreaOfInterest $area, array $folded): void
    {
        $row = $this->write($person, $area);
        $row?->fold($folded);

        $this->entityManager->flush();
    }

    private function of(?UserInterface $person, AreaOfInterest $area): ?SheetPreference
    {
        return null === $person ? null : $this->preferences->findOneByPersonAndArea($person, $area);
    }

    /** The row to write on, created the first time somebody expresses a preference. */
    private function write(?UserInterface $person, AreaOfInterest $area): ?SheetPreference
    {
        if (null === $person) {
            return null;
        }

        $row = $this->preferences->findOneByPersonAndArea($person, $area);
        if (null === $row) {
            $row = new SheetPreference($person, $area);
            $this->entityManager->persist($row);
        }

        return $row;
    }
}
