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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\RosterOrgService;
use Uhifadhi\Roster\Widget\RosterOrgWidgets;

/**
 * THE ROSTER AT ORGANISATION SCOPE — three screens, read across every area.
 *
 * THE SHELL DRAWS THE CHROME. The row in Observatory, the tab strip and the
 * scope control are the shell's, mounted from what this module's provider
 * names in `orgPages()`; nothing here writes a sidebar item, a tab or a
 * control, and the host wires no module code.
 *
 * EVERY FIGURE IS THE AREA QUERY ONE SCOPE WIDER. Not one number on these
 * pages is computed here: {@see RosterOrgService} walks the areas the scope
 * reaches and folds what the area page's own services answer. Two code
 * paths would drift, and the day they disagreed nobody would know which was
 * right.
 *
 * THE SCOPE IS THE SHELL'S ANSWER AND ALREADY NARROWED to what this account
 * may open, so there is no access check here and there must not be: an area
 * that reaches this controller is one the reader may see. A person scoped to
 * a single area gets that area selected, no control, and the same page.
 */
final class RosterOrgController
{
    public const string OVERVIEW_ROUTE = 'roster_org_overview';
    public const string TODAY_ROUTE = 'roster_org_today';
    public const string LIVE_ROUTE = 'roster_org_live';

    /** Where a widget's own partial lives, as the library's sprintf format. */
    public const string PARTIAL = '@UhifadhiRoster/dashboard/org/_w_%s.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly RosterOrgService $org,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $endpoint,
        private readonly Scopes $scopes,
        private readonly RosterModuleProvider $pages,
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requests,
    ) {
    }

    #[Route('/roster', name: self::OVERVIEW_ROUTE, methods: ['GET'])]
    public function overview(): Response
    {
        [$scope, $areas, $day, $now] = $this->reading();

        return new Response($this->twig->render('@UhifadhiRoster/org/overview.html.twig', [
            ...$this->common($scope, $areas, $day, $now),
            // THE SURFACE IS RESOLVED PER PERSON AND NOT PER AREA: an
            // organisation-level arrangement is one somebody chose once,
            // and there is no area for it to hang on.
            'widgets' => $this->widgets->resolve(RosterOrgWidgets::declaration(), $this->endpoint->user(), null),
            'plate' => $this->org->plate($areas, $now),
        ]));
    }

    #[Route('/roster/today', name: self::TODAY_ROUTE, methods: ['GET'])]
    public function today(): Response
    {
        [$scope, $areas, $day, $now] = $this->reading();

        return new Response($this->twig->render('@UhifadhiRoster/org/today.html.twig', [
            ...$this->common($scope, $areas, $day, $now),
            'rows' => $this->org->agenda($areas, $day, $now),
        ]));
    }

    #[Route('/roster/live', name: self::LIVE_ROUTE, methods: ['GET'])]
    public function live(): Response
    {
        [$scope, $areas, $day, $now] = $this->reading();

        return new Response($this->twig->render('@UhifadhiRoster/org/live.html.twig', [
            ...$this->common($scope, $areas, $day, $now),
            'plate' => $this->org->plate($areas, $now),
        ]));
    }

    /**
     * WHAT EVERY ONE OF THE THREE READS — one scope, one set of areas, one
     * instant. Asked once so the three pages cannot disagree about which
     * minute they are drawing.
     *
     * @return array{0: Scope, 1: list<\Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest>, 2: \DateTimeImmutable, 3: \DateTimeImmutable}
     */
    private function reading(): array
    {
        $scope = $this->scopes->current() ?? Scope::organisation();
        $now = new \DateTimeImmutable();

        return [$scope, $this->org->areasIn($scope, $this->scopes->available()), $now->setTime(0, 0), $now];
    }

    /**
     * THE STRIP, from the declaration the shell mounts. A route this
     * installation has not mounted is LEFT OUT rather than drawn as a link
     * to a 404 — the same rule the shell applies to the sidebar.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function tabs(): array
    {
        $here = $this->requests->getCurrentRequest()?->attributes->get('_route');
        $tabs = [];

        foreach ($this->pages->orgPages() as $page) {
            try {
                $url = $this->router->generate($page->route);
            } catch (\Symfony\Component\Routing\Exception\ExceptionInterface) {
                continue;
            }

            $tabs[] = ['label' => $page->label, 'url' => $url, 'current' => $page->route === $here];
        }

        return $tabs;
    }

    /**
     * @param list<\Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest> $areas
     *
     * @return array<string, mixed>
     */
    private function common(Scope $scope, array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now): array
    {
        return [
            'scope' => $scope,
            // THE CONTROL'S OWN ROWS AND THE PAGE'S OWN STRIP — both built
            // from what the shell mounted, so a tab and a sidebar screen
            // can never disagree about which pages exist.
            'scopeOptions' => $this->scopes->available(),
            'orgTabs' => $this->tabs(),
            'areas' => $areas,
            'day' => $day,
            'now' => $now,
            'figures' => $this->org->figuresFor($areas, $day, $now),
            'bands' => $this->org->bands($areas, $day, $now),
            'decisions' => $this->org->decisions($areas, $day, $now),
        ];
    }
}
