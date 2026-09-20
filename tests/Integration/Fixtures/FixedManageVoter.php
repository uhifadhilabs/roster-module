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

namespace Uhifadhi\Roster\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\AreaBundle\Access\AreaPermissions;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;

/**
 * Test stand-in for the INSTALLATION's permission voter: this module only
 * DECLARES "roster.manage"; deciding who holds it is Team's job.
 *
 * TWO ACCOUNTS AND A DIFFERENT ANSWER FOR EACH, on purpose. A blanket "may do
 * everything" stub could never show the case the configure page is built
 * around — somebody who can READ how the area is set up and cannot change
 * it — and that reader is the commonest visitor the page has.
 *
 * @extends Voter<string, mixed>
 */
final class FixedManageVoter extends Voter
{
    /** Holds both: may change the rotations, and may offer a watch. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    /** Holds nothing: reads the configure page and saves nothing. */
    public const string READER_EMAIL = 'reader@example.test';

    /**
     * READING AN AREA — the platform's own word, spelt where the area's
     * Stations configure page checks it. It is in the host's catalogue and
     * not in this bundle's, so there is no constant upstream to import.
     */
    public const string AREA_VIEW = 'area.view';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [
            RosterConfigureController::MANAGE_PERMISSION,
            RosterController::PLAN_PERMISSION,
            // The AREA's own two, neither of them this module's to declare
            // and neither of them ever checked by it. They are granted here
            // because this module CONTRIBUTES to the area's own screens and
            // answers the area's own endpoint, and a suite that could not
            // open either would be testing the contribution in a vacuum.
            AreaPermissions::CHECK_IN,
            self::AREA_VIEW,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // ANY SIGNED-IN ACCOUNT MAY READ THE PARK AND REPORT ITS OWN DAY.
        // The two roster permissions are the manager's alone; check-in is
        // every ranger's, which is what makes "me" mean the token's
        // account.
        if (\in_array($attribute, [AreaPermissions::CHECK_IN, self::AREA_VIEW], true)) {
            return true;
        }

        return self::MANAGER_EMAIL === $user->getEmail();
    }
}
