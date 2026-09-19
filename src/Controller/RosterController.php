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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Model\AgendaFilter;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Service\AgendaService;
use Uhifadhi\Roster\Service\DayBoardService;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\RosterCalendar;
use Uhifadhi\Roster\Service\RosteredPeople;
use Uhifadhi\Roster\Service\RosterFiguresService;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\SwapCostService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Service\WeekGridService;
use Uhifadhi\Roster\Widget\RosterWidgets;

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

    /** Offering a watch to somebody, answered on the handset. */
    public const string OFFER_SWAP_ROUTE = 'roster_swap_offer';

    /** Taking an offer back, before it has been answered. */
    public const string WITHDRAW_SWAP_ROUTE = 'roster_swap_withdraw';

    /**
     * WHAT A PERSON MUST HOLD TO OFFER A WATCH TO SOMEBODY ELSE.
     *
     * Its own permission and not `roster.manage`: moving one watch between
     * two people on one night is a duty officer's daily work, and making it
     * need the permission that rewrites the area's rotations would push
     * every shift change up to whoever holds that.
     */
    public const string PLAN_PERMISSION = 'roster.plan';

    /** One token id for the writes this controller makes. */
    public const string CSRF_TOKEN_ID = 'roster_week';

    /** The agenda: who is due today, post by post, with how each day reads. */
    public const string TODAY_ROUTE = 'roster_today';

    /** The day as a wall: twenty-four hours across, one post per row. */
    public const string BOARD_ROUTE = 'roster_board';

    /** One ranger's month, in the house calendar. */
    public const string CALENDAR_ROUTE = 'roster_calendar';

    /** What is true this minute. */
    public const string LIVE_ROUTE = 'roster_live';

    /**
     * HOW MANY ROWS A DASHBOARD CARD SHOWS BEFORE IT SAYS HOW MANY THERE
     * WERE. A card's height never grows with its data (ruled): the rest are
     * one click away on the tab that is built to list them.
     */
    public const int DECISIONS_SHOWN = 6;

    public const int STATIONS_SHOWN = 6;

    public function __construct(
        private readonly Environment $twig,
        private readonly RosterIdentityService $identity,
        private readonly WeekGridService $week,
        private readonly RotaService $rota,
        private readonly PresenceReader $presence,
        private readonly DayBoardService $board,
        private readonly RosteredPeople $people,
        private readonly RosterCalendar $calendar,
        private readonly SwapService $swaps,
        private readonly SwapCostService $cost,
        private readonly RosterFiguresService $figures,
        private readonly AgendaService $agenda,
        private readonly ShiftRepository $shifts,
        private readonly WidgetService $widgetService,
        private readonly UrlGeneratorInterface $router,
        /*
         * NULL WHERE THE INSTALLATION RUNS NO SECURITY. There the week tab
         * offers no swap — there is nobody to attribute an offer to and
         * nothing to refuse one with — and the page reads as a plan rather
         * than a form that cannot post.
         */
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * THE TWO THINGS EVERY SWAP WRITE ASKS: does this person hold
     * `roster.plan`, and did the request come from the page.
     */
    private function guardPlan(AreaOfInterest $area, Request $request): void
    {
        if (null === $this->authorization || !$this->authorization->isGranted(self::PLAN_PERMISSION, $area)) {
            throw new AccessDeniedHttpException('Offering a watch to somebody needs the "roster.plan" permission.');
        }

        $token = $request->request->get('_token');
        if (null === $this->csrfTokenManager || !\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /** Who is offering. Null where the installation runs no security. */
    private function viewer(): ?UserInterface
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Say it in the frame's own flashes, where the session carries a bag —
     * `SessionInterface` does not promise one, and losing a sentence is a
     * smaller failure than a 500.
     */
    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
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
        $day = new \DateTimeImmutable('today');

        // ONE READ OF THE DAY, handed to every widget that needs it. Five
        // cards asking the presence seam separately is five chances for one
        // screen to disagree with itself about the same morning.
        $posts = $this->presence->postsOn($area, $day);
        $gaps = $this->week->gaps($area, $day, $day->modify(\sprintf('+%d days', RosterFiguresService::HOLE_HORIZON_DAYS - 1)));

        return new Response($this->twig->render('@UhifadhiRoster/overview/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'posts' => $posts,
            'figures' => $this->figures->forDay($area, $day),
            'decisions' => RosterFiguresService::decisions($posts, $gaps),
            'rosterDecisionLimit' => self::DECISIONS_SHOWN,
            'rosterStationLimit' => self::STATIONS_SHOWN,
            // WHICH WIDGETS, HOW WIDE, IN WHAT ORDER — the shell's widget
            // framework resolving this surface's catalogue: the shipped
            // composition until somebody changes it in the library.
            'widgets' => $this->widgetService->resolve(RosterWidgets::declaration(), $this->viewer(), $area->getUuid()),
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
        $from = RotaService::start($this->askedFor($request) ?? new \DateTimeImmutable('today'));
        $through = $from->modify(\sprintf('+%d days', RotaService::DAYS - 1));

        return new Response($this->twig->render('@UhifadhiRoster/week/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'from' => $from,
            'through' => $through,
            'today' => new \DateTimeImmutable('today'),
            'days' => $this->rota->days($from),
            'groups' => $this->rota->groups($area, $from, $through),
            'figures' => $this->rota->figures($area, $from, $through),
            'gaps' => $this->week->gaps($area, $from, $through),
            'previous' => $from->modify(\sprintf('-%d days', RotaService::DAYS)),
            'next' => $from->modify(\sprintf('+%d days', RotaService::DAYS)),
            // THE SWAP FLOW: the register of offers over this window, and
            // the trade being put together, if one is.
            'swaps' => $this->swaps->openBetween($area, $from, $through),
            'recent' => $this->swaps->recentBetween($area, $from, $through, 5),
            'offering' => $offering = $this->swaps->offering($area, $request->query->get('give'), $request->query->get('take')),
            'cost' => null === $offering ? null : $this->cost->of($offering['duty'], $offering['taking']),
            'candidates' => $this->people->rosteredIn($area),
            // NULL WHERE THE INSTALLATION RUNS NO SECURITY: no checker, so
            // nobody may plan, and the card reads as a plan rather than a
            // form that cannot post.
            'mayPlan' => null !== $this->authorization && $this->authorization->isGranted(self::PLAN_PERMISSION, $area),
            'csrfToken' => $this->csrfTokenManager?->getToken(self::CSRF_TOKEN_ID)->getValue() ?? '',
        ]));
    }

    /**
     * OFFER A WATCH. It moves nobody: until the handset accepts, both duties
     * stand exactly as the rotation generated them.
     */
    #[Route('/areas/{uuid}/modules/roster/week/offer', name: self::OFFER_SWAP_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function offerSwap(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardPlan($area, $request);

        try {
            $this->swaps->offerFromRequest(
                $area,
                (string) $request->request->get('duty'),
                (string) $request->request->get('taking'),
                $this->viewer(),
            );
        } catch (\LogicException|\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return new RedirectResponse($this->router->generate(self::WEEK_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /** Take an offer back, before it has been answered. */
    #[Route('/areas/{uuid}/modules/roster/week/withdraw', name: self::WITHDRAW_SWAP_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function withdrawSwap(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardPlan($area, $request);

        try {
            $this->swaps->withdrawFromRequest($area, (string) $request->request->get('swap'), new \DateTimeImmutable());
        } catch (\LogicException|\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return new RedirectResponse($this->router->generate(self::WEEK_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /**
     * TODAY — the agenda, post by post: who is due, and how each of their
     * days actually reads.
     *
     * THE PLAN AND THE MEASUREMENT SIDE BY SIDE AND NEVER MERGED. The watch
     * is this module's; the state beside it is the area's reading of that
     * person's own check-in and pings. The gap between the two is the point
     * of the module, and collapsing them into one verdict would throw it
     * away.
     */
    #[Route('/areas/{uuid}/modules/roster/today', name: self::TODAY_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function today(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = $this->askedFor($request) ?? new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        $filter = AgendaFilter::fromQuery(
            $request->query->get('post'),
            $request->query->get('state'),
            $request->query->get('shift'),
            $request->query->get('q'),
        );

        // ONE READ OF THE DAY. The figures are folded over the WHOLE of it
        // and the rows are the narrowed copy, so narrowing to one post never
        // quietly rewrites the strip above it.
        $whole = $this->presence->postsOn($area, $day, $now);
        $windows = $this->shifts->windowsFor($area);

        return new Response($this->twig->render('@UhifadhiRoster/today/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'tomorrow' => $day->modify('+1 day'),
            'isToday' => $day->format('Y-m-d') === $now->format('Y-m-d'),
            'filter' => $filter,
            'posts' => $this->agenda->narrow($whole, $filter, $now, $windows),
            'tomorrowPosts' => $this->agenda->tomorrow($area, $day),
            'figures' => $this->agenda->figuresFor($area, $whole, $day, $now),
            'chosenPost' => $this->chosenPost($whole, $filter),
            'stateLabels' => self::stateLabels(),
            'stateCounts' => self::stateCounts($whole),
            'shiftLabels' => $this->shiftLabels($area),
            'shiftCounts' => self::shiftCounts($whole),
        ]));
    }

    /**
     * THE DAY BOARD — the day as a wall: twenty-four hours across, one post
     * per row, a block for every watch and a line where "now" is.
     *
     * A NIGHT WATCH IS TWO BLOCKS. It crosses midnight, and drawing it as
     * one would be a lie about the day it belongs to: the part before 06:00
     * belongs to the watch that began yesterday.
     */
    #[Route('/areas/{uuid}/modules/roster/board', name: self::BOARD_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function board(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = $this->askedFor($request) ?? new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        return new Response($this->twig->render('@UhifadhiRoster/board/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'posts' => $this->presence->postsOn($area, $day, $now),
            'blocks' => $this->board->blocksFor($area, $day),
            'nowPercent' => $day->format('Y-m-d') === $now->format('Y-m-d') ? $this->board->percentOfDay($now) : null,
        ]));
    }

    /**
     * THE CALENDAR — one ranger's month, drawn in the HOUSE calendar.
     *
     * The grid, the cell, its fixed height and the stepper are the atlas's;
     * this module says only what happened on which day. It ships no month
     * grid of its own, which is the whole reason the component exists.
     */
    #[Route('/areas/{uuid}/modules/roster/calendar', name: self::CALENDAR_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function calendar(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $people = $this->people->rosteredIn($area);
        $chosen = $this->people->choose($people, $request->query->get('ranger'));
        $month = $this->people->monthOf($request->query->get('month'));

        return new Response($this->twig->render('@UhifadhiRoster/calendar/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'people' => $people,
            'chosen' => $chosen,
            'month' => $month,
            'scope' => null === $chosen ? null : RosterCalendar::scopeFor((string) $area->getUuidString(), $chosen['uuid']),
            'feed' => $this->calendar,
        ]));
    }

    /**
     * LIVE — what is true this minute, and the roster underneath it.
     *
     * THE POSITIONS ARE THE AREA'S. This module stores none and draws none
     * of its own: the plate and its markers belong to whoever owns the
     * ground, and the roster reads the states over them.
     */
    #[Route('/areas/{uuid}/modules/roster/live', name: self::LIVE_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function live(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $day = new \DateTimeImmutable('today');

        return new Response($this->twig->render('@UhifadhiRoster/live/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'now' => new \DateTimeImmutable(),
            'posts' => $this->presence->postsOn($area, $day),
        ]));
    }

    /**
     * THE POST THE FILTER NAMES, so the chip can wear its name rather than
     * a uuid. A uuid nothing matches reads as "all posts", which is what the
     * page is in fact showing.
     *
     * @param list<PostPresence> $posts
     */
    private function chosenPost(array $posts, AgendaFilter $filter): ?PostPresence
    {
        foreach ($posts as $post) {
            if ($post->stationUuid === $filter->post) {
                return $post;
            }
        }

        return null;
    }

    /**
     * THE STATES THE FILTER OFFERS. The area's own day states, plus the one
     * non-state the design names: DUE, a watch that has not begun. A ranger
     * who has not checked in at 11:42 for an 18:00 watch has failed at
     * nothing, and offering them under "no check-in" would say they had.
     *
     * @return array<string, string>
     */
    private static function stateLabels(): array
    {
        $labels = [];
        foreach (DayState::cases() as $state) {
            $labels[$state->value] = $state->label();
        }

        $labels[AgendaService::DUE] = 'Due later today';

        return $labels;
    }

    /**
     * HOW MANY PEOPLE ARE IN EACH STATE, counted from the same reading the
     * rows came from — never a second query, which is how an option and a
     * list come to disagree.
     *
     * @param list<PostPresence> $posts
     *
     * @return array<string, int>
     */
    private static function stateCounts(array $posts): array
    {
        $counts = ['all' => 0];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                ++$counts['all'];
                $key = $person->state()->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param list<PostPresence> $posts
     *
     * @return array<string, int>
     */
    private static function shiftCounts(array $posts): array
    {
        $counts = [];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $counts[$person->shiftKey] = ($counts[$person->shiftKey] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * THE AREA'S OWN SHIFT VOCABULARY, which is the only list a shift filter
     * may offer: a hard-coded day/night pair would be wrong in any park that
     * named its shifts differently, and every one of them does.
     *
     * @return array<string, string>
     */
    private function shiftLabels(AreaOfInterest $area): array
    {
        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            if ($shift->isOpen()) {
                $labels[$shift->getKey()] = $shift->getLabel().' '.$shift->getStartsAt().'–'.$shift->getEndsAt();
            }
        }

        return $labels;
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
