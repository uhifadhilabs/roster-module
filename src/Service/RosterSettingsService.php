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
use Uhifadhi\Roster\Entity\AreaRosterSettings;
use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Enum\VacancyAnnounce;
use Uhifadhi\Roster\Repository\AreaRosterSettingsRepository;

/**
 * WHAT AN AREA RUNS ON — read with a row created on first ask, written by the
 * Settings section.
 *
 * CREATE-ON-READ, and it is the reason this is a service rather than a
 * repository call. An area that has never opened the Settings section still
 * has to answer "how often does a handset ping" for every surface in the
 * module, and the honest answer is the installation's starting value — stored
 * the first time somebody asks, so that from then on the park's own number is
 * the one thing anybody has to look at. Nothing reads the config again once
 * the row exists, which is what makes a later change to `roster.defaults`
 * affect new areas and leave settled ones alone.
 */
final readonly class RosterSettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AreaRosterSettingsRepository $settings,
        private int $defaultPingIntervalMinutes,
        private int $defaultCatchmentMetres,
    ) {
    }

    /** This area's settings, created from the installation's starting values if it has none. */
    public function forArea(AreaOfInterest $area): AreaRosterSettings
    {
        $settings = $this->settings->findOneForArea($area);

        if (null === $settings) {
            $settings = new AreaRosterSettings($area, $this->defaultPingIntervalMinutes, $this->defaultCatchmentMetres);
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        return $settings;
    }

    /**
     * SAVE THE SIX, ALL AT ONCE. The Settings section is one form with one
     * Save, so it is one write: a partial save would leave the page showing a
     * mixture of what was submitted and what was not.
     */
    public function save(
        AreaOfInterest $area,
        int $pingIntervalMinutes,
        bool $offDayHasNoState,
        bool $leaveApprovalShown,
        int $defaultCatchmentMetres,
        LateThreshold $lateThreshold,
        VacancyAnnounce $vacancyAnnounce,
    ): AreaRosterSettings {
        $settings = $this->forArea($area);

        $settings
            ->setPingIntervalMinutes($pingIntervalMinutes)
            ->setOffDayHasNoState($offDayHasNoState)
            ->setLeaveApprovalShown($leaveApprovalShown)
            ->setDefaultCatchmentMetres($defaultCatchmentMetres)
            ->setLateThreshold($lateThreshold)
            ->setVacancyAnnounce($vacancyAnnounce);

        $this->entityManager->flush();

        return $settings;
    }
}
