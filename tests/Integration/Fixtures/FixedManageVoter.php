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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Controller\RosterConfigureController;

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
    /** Holds roster.manage: may change the rotations, the watches and the settings. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    /** Holds nothing: reads the configure page and saves nothing. */
    public const string READER_EMAIL = 'reader@example.test';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return RosterConfigureController::MANAGE_PERMISSION === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && self::MANAGER_EMAIL === $user->getEmail();
    }
}
