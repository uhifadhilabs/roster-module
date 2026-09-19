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

namespace Uhifadhi\Roster\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\RosterIdentityService;

/**
 * THE ROSTER'S OWN PAGES, at /areas/{uuid}/modules/roster.
 *
 * THE MODULE MARKER IS SPELLED OUT rather than imported from the registry's
 * constant. A class constant in a route attribute is a LOAD-TIME dependency,
 * and while this module does require the core, a module's own marker is one
 * string it should be able to say for itself; a seam test asserts the two
 * agree. Without the marker a page is not exempt from the per-area gate — it
 * is guessed at.
 *
 * WHERE AN AREA HAS PARKED THIS MODULE, EVERY PAGE HERE IS 404. The registry
 * owns the ledger and enforces it in one listener; nothing in this class asks.
 * 404 and not 403: a parked module is not withheld, the area is not running
 * it.
 *
 * NO BASE CLASS. A reusable bundle's controller does not extend
 * AbstractController — that ties it to a service-subscriber container it
 * cannot assume and hides its dependencies behind a container lookup. It takes
 * what it needs in its constructor and is wired explicitly.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterController
{
    /**
     * The module's front door, and the route its catalogue tile links to.
     * Published as a constant because three other classes name it — the tab
     * declaration, the configure screens' trail, and the provider.
     */
    public const string OVERVIEW_ROUTE = 'roster_overview';

    public function __construct(
        private readonly Environment $twig,
        private readonly RosterIdentityService $identity,
    ) {
    }

    /**
     * THE OVERVIEW TAB — the header, the six-tab strip, the identity band,
     * then the composed surface.
     *
     * THE SURFACE IS NOT HERE YET, and the reason is worth stating rather
     * than filling with a placeholder: every widget the shipped composition
     * names — the day's check-ins, what needs a decision, the posts and who
     * the pings put on them, the plate — is a reading of PRESENCE, and
     * presence is the area's to answer through a seam that has not landed.
     * Drawing any of it today would mean inventing the one thing this module
     * is ruled never to invent.
     */
    #[Route('/areas/{uuid}/modules/roster', name: self::OVERVIEW_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function overview(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return new Response($this->twig->render('@UhifadhiRoster/overview/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
        ]));
    }
}
