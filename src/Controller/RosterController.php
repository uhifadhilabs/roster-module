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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\WeekGridService;

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

    /** The planner's tab: posts down, days across, every hole drawn as a hole. */
    public const string WEEK_ROUTE = 'roster_week';

    public function __construct(
        private readonly Environment $twig,
        private readonly RosterIdentityService $identity,
        private readonly WeekGridService $week,
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

    /**
     * THE WEEK — posts down, days across, and every hole drawn as a hole.
     *
     * IT STARTS ON A MONDAY whatever day somebody opens it. A week that began
     * on the day you happened to look is not a week anybody plans in, and two
     * people comparing notes would be comparing different weeks.
     *
     * `?from=` moves it, and an unreadable one falls back to this week rather
     * than failing: a mistyped date in a url is not worth a 500, and the page
     * says which week it is drawing.
     */
    #[Route('/areas/{uuid}/modules/roster/week', name: self::WEEK_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function week(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $from = WeekGridService::weekStart($this->askedFor($request) ?? new \DateTimeImmutable('today'));
        $through = $from->modify('+6 days');

        return new Response($this->twig->render('@UhifadhiRoster/week/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'from' => $from,
            'through' => $through,
            'today' => new \DateTimeImmutable('today'),
            'days' => $this->week->days($from, $through),
            'rows' => $this->week->rows($area, $from, $through),
            'gaps' => $this->week->gaps($area, $from, $through),
            'shifts' => $this->week->shiftsOf($area),
            'previous' => $from->modify('-7 days'),
            'next' => $from->modify('+7 days'),
        ]));
    }

    /**
     * THE WEEK THE URL ASKED FOR, or null where it asked for nothing
     * readable. Untrusted like every query field.
     */
    private function askedFor(Request $request): ?\DateTimeImmutable
    {
        $from = $request->query->get('from');
        if (!\is_string($from)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);

        return false === $parsed ? null : $parsed;
    }
}
