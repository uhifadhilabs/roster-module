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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Enum\VacancyAnnounce;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;

/**
 * THE THREE SECTIONS OF THE ROSTER'S CONFIGURE PAGE — Rotation, Watches,
 * Settings — each at an address of its own so it can link the stylesheet it
 * draws with.
 *
 * EACH ONE STILL BELONGS TO THE CONFIGURE PAGE. It wears the page's heading
 * and the page's section strip, and the Configure action stays lit on it,
 * because the template adopts the same frame. Nothing here draws navigation,
 * and there is no way back other than the strip, the lit Configure and the
 * crumb.
 *
 * THEY SIT BESIDE /configure AND NEVER UNDER IT. The shell owns
 * `/areas/{uuid}/modules/{slug}/configure/{section}` and matches any
 * lowercase slug, so a section screen addressed under that prefix would race
 * the frame's own route and the winner would depend on import order. A
 * section's own address is one segment under the module instead, and the
 * shell's bare `/configure` answers 302 to the first of them — one rule,
 * both shapes.
 *
 * EVERY WRITE RIDES ON "roster.manage" AND A CSRF TOKEN. The permission check
 * is in CODE rather than an #[IsGranted] attribute, so the class stays
 * loadable in an installation with no security-bundle attributes to resolve —
 * and the whole controller is registered only where SecurityBundle is in the
 * kernel, so a security-less installation gets no routes at all rather than
 * open write endpoints.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterConfigureController
{
    public const string ROTATION_ROUTE = 'roster_configure_rotation';
    public const string WATCHES_ROUTE = 'roster_configure_watches';
    public const string SETTINGS_ROUTE = 'roster_configure_settings';

    public const string SAVE_WATCHES_ROUTE = 'roster_configure_watches_save';
    public const string SAVE_SETTINGS_ROUTE = 'roster_configure_settings_save';

    /**
     * WHAT A PERSON MUST HOLD TO CHANGE HOW THIS AREA'S ROSTER IS SET UP.
     *
     * Declared by the module provider against this exact constant, and read
     * here. The string is never retyped: a permission whose declaration and
     * whose check differ by one character is a screen nobody can open and an
     * admin checkbox that grants nothing.
     */
    public const string MANAGE_PERMISSION = 'roster.manage';

    /** One token id for the whole configure page; each form carries it. */
    public const string CSRF_TOKEN_ID = 'roster_configure';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly RosterIdentityService $identity,
        private readonly RosterSettingsService $settings,
        private readonly ShiftVocabularyService $shifts,
        private readonly StationWatchService $watches,
        private readonly StationRepository $stations,
        private readonly RotationRepository $rotations,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/areas/{uuid}/modules/roster/rotation', name: self::ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function rotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $rotations = $this->rotations->findByArea($area);

        return new Response($this->twig->render('@UhifadhiRoster/configure/rotation.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'rotations' => $rotations,
            // The posts the area registers that run no ring at all. Named
            // rather than counted: "a post with no rotation is never counted,
            // reported on or called a hole" is only trustworthy if the page
            // says which posts it means.
            'postsWithoutARotation' => $this->postsWithoutARotation($area),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
        ]));
    }

    #[Route('/areas/{uuid}/modules/roster/watches', name: self::WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function watches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $watches = $this->watches->forArea($area);

        return new Response($this->twig->render('@UhifadhiRoster/configure/watches.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'watches' => $watches,
            'pools' => $this->poolSizes($watches),
            'shifts' => $this->shifts->openFor($area),
            // The area's other posts — what "Add a post to the roster" offers.
            'postsOffTheBooks' => $this->postsOffTheBooks($area, $watches),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function settings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return new Response($this->twig->render('@UhifadhiRoster/configure/settings.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'settings' => $this->settings->forArea($area),
            'shifts' => $this->shifts->forArea($area),
            'lateThresholds' => LateThreshold::cases(),
            'vacancyAnnouncements' => VacancyAnnounce::cases(),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * SAVE EVERY WATCH ON THE PAGE, in one write, because the section is one
     * form with one Save. A per-row save would let somebody leave the page
     * having changed four of six and believing they changed six.
     */
    #[Route('/areas/{uuid}/modules/roster/watches', name: self::SAVE_WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveWatches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        foreach ($this->watches->forArea($area) as $watch) {
            $id = (string) $watch->getStation()->getId();

            $this->watches->save(
                $watch,
                $this->shiftKeys($request, 'expects_'.$id),
                $this->positiveInt($request, 'silence_'.$id, $watch->getSilenceWindowMinutes()),
                $this->positiveInt($request, 'offline_'.$id, $watch->getOfflineAfterMinutes()),
                $this->positiveInt($request, 'catchment_'.$id, $watch->getCatchmentMetres()),
            );
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SAVE_SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveSettings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $current = $this->settings->forArea($area);

        $this->settings->save(
            $area,
            $this->positiveInt($request, 'ping_interval_minutes', $current->getPingIntervalMinutes()),
            $request->request->getBoolean('off_day_has_no_state', $current->offDayHasNoState()),
            $request->request->getBoolean('leave_approval_shown', $current->isLeaveApprovalShown()),
            $this->positiveInt($request, 'default_catchment_metres', $current->getDefaultCatchmentMetres()),
            LateThreshold::tryFrom((string) $request->request->get('late_threshold')) ?? $current->getLateThreshold(),
            VacancyAnnounce::tryFrom((string) $request->request->get('vacancy_announce')) ?? $current->getVacancyAnnounce(),
        );

        return $this->backTo(self::SETTINGS_ROUTE, $area);
    }

    /**
     * THE TWO THINGS EVERY WRITE ON THIS PAGE ASKS, in one place so neither
     * can be forgotten on the next form: does this person hold the
     * permission, and did this request come from the page.
     */
    private function guardWrite(AreaOfInterest $area, Request $request): void
    {
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION, $area)) {
            throw new AccessDeniedHttpException('Changing how this area runs its roster needs the "roster.manage" permission.');
        }

        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /**
     * A NUMBER OUT OF A FORM IS UNTRUSTED. A blank, a word or a negative is
     * the value that was already stored — the entity refuses nonsense anyway,
     * and answering a fat-fingered field with a 500 helps nobody.
     */
    private function positiveInt(Request $request, string $field, int $fallback): int
    {
        $value = $request->request->getInt($field);

        return $value > 0 ? $value : $fallback;
    }

    /**
     * @return list<string>
     */
    private function shiftKeys(Request $request, string $field): array
    {
        $submitted = $request->request->all($field);

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => \is_string($key) ? $key : '', $submitted),
            static fn (string $key): bool => '' !== $key,
        ));
    }

    /**
     * @param list<StationWatch> $watches
     *
     * @return array<int, int> station id => how many people its ring draws from
     */
    private function poolSizes(array $watches): array
    {
        $pools = [];
        foreach ($watches as $watch) {
            $id = $watch->getStation()->getId();
            if (null === $id) {
                continue;
            }

            $pools[$id] = $this->watches->poolSizeFor($watch->getStation());
        }

        return $pools;
    }

    /**
     * @param list<StationWatch> $watches
     *
     * @return list<Station>
     */
    private function postsOffTheBooks(AreaOfInterest $area, array $watches): array
    {
        $onTheBooks = [];
        foreach ($watches as $watch) {
            $onTheBooks[(string) $watch->getStation()->getId()] = true;
        }

        return array_values(array_filter(
            $this->stations->findByArea($area),
            static fn (Station $station): bool => !isset($onTheBooks[(string) $station->getId()]),
        ));
    }

    /**
     * @return list<Station>
     */
    private function postsWithoutARotation(AreaOfInterest $area): array
    {
        return array_values(array_filter(
            $this->stations->findByArea($area),
            fn (Station $station): bool => null === $this->rotations->findOneForStation($station),
        ));
    }

    /**
     * BACK TO THE SECTION THAT WAS SAVED, as a redirect: a POST answered with
     * a rendered page is a page a refresh re-submits.
     */
    private function backTo(string $route, AreaOfInterest $area): RedirectResponse
    {
        $uuid = $area->getUuidString();

        if (null === $uuid) {
            throw new NotFoundHttpException('That area has no identifier to return to.');
        }

        return new RedirectResponse($this->router->generate($route, ['uuid' => $uuid]));
    }
}
