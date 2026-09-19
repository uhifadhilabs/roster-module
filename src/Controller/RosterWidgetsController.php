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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\RosterDashboardService;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterWidgetUrls;
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE ROSTER'S WIDGET LIBRARY — the first tab of the module's configure page.
 *
 * IT IS NOT A DOOR ON THE DASHBOARD. Ruled: editing never happens on the
 * surface being edited, and the library is the first section of Configure on
 * every module in the platform. So this screen wears the configure page's
 * heading and its section strip, exactly as Rotation, Watches and Settings
 * do, and the dashboard carries no "customize" control at all.
 *
 * THE MECHANICS ARE THE SHELL'S, WHOLE. The page is chrome; everything under
 * it is `@Shell/widget/_library.html.twig` parameterised by this surface's
 * catalogue, its partial name and its routes, and every write goes through
 * {@see WidgetEndpoint}. There is no roster-specific widget machinery
 * anywhere, which is the only way a widget can be guaranteed to behave the
 * same here as on every other surface.
 *
 * EVERY PREVIEW IS THE REAL WIDGET ON REAL DATA, built from the SAME context
 * the dashboard hands its partials. A picture of a widget that came from
 * somewhere else is a picture that eventually stops matching what gets added.
 *
 * REGISTERED ONLY WHERE SECURITYBUNDLE IS. Arranging a dashboard is a write
 * attributed to a person; without a firewall there is nobody to attribute it
 * to, so the screen does not exist rather than existing unattributed.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterWidgetsController
{
    public const string LIBRARY_ROUTE = 'roster_widgets';
    public const string SAVE_ROUTE = 'roster_widgets_save';
    public const string RESET_ROUTE = 'roster_widgets_reset';
    public const string PRESET_ROUTE = 'roster_widgets_preset';
    public const string PRESET_COPY_ROUTE = 'roster_widgets_preset_copy';
    public const string PRESET_CREATE_ROUTE = 'roster_widgets_preset_create';
    public const string PRESET_APPLY_ROUTE = 'roster_widgets_preset_apply';
    public const string PRESET_RENAME_ROUTE = 'roster_widgets_preset_rename';
    public const string PRESET_DELETE_ROUTE = 'roster_widgets_preset_delete';

    /** Where a widget's own partial lives, as the library's sprintf format. */
    public const string PARTIAL = '@UhifadhiRoster/dashboard/_w_%s.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $endpoint,
        private readonly RosterWidgetUrls $urls,
        private readonly RosterDashboardService $dashboard,
        private readonly RosterIdentityService $identity,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/roster/widgets',
        name: self::LIBRARY_ROUTE,
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function library(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $catalog = RosterWidgets::declaration();
        $viewer = $this->endpoint->user();
        $areaUuid = $area->getUuid();
        $now = new \DateTimeImmutable();

        return new Response($this->twig->render('@UhifadhiRoster/widgets/library.html.twig', [
            'area' => $area,
            // The configure frame draws the identity band, so every screen
            // that wears the frame owes it the same figures.
            'band' => $this->identity->bandFor($area),
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $viewer, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $viewer, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $viewer, $areaUuid),
            'partial' => self::PARTIAL,
            // THE SAME CONTEXT THE DASHBOARD USES. The preview is the widget.
            'widgetContext' => [
                'area' => $area,
                'dash' => $this->dashboard->build($area, new \DateTimeImmutable('today'), $now),
                'rosterDecisionLimit' => RosterController::DECISIONS_SHOWN,
                'rosterStationLimit' => RosterController::STATIONS_SHOWN,
            ],
            'urls' => $this->urls->forArea($area),
            'csrfToken' => $this->endpoint->csrfToken($catalog, $areaUuid),
        ]));
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/save', name: self::SAVE_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->endpoint->save($request, RosterWidgets::declaration(), $area->getUuid());
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/reset', name: self::RESET_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function reset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->reset($request, RosterWidgets::declaration(), $area->getUuid()),
            \sprintf('This area’s roster dashboard is back to “%s”.', RosterWidgets::DEFAULT_LABEL),
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/preset/{presetId}', name: self::PRESET_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 2)]
    public function applyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        $catalog = RosterWidgets::declaration();
        // A design the surface does not ship is refused by the endpoint;
        // naming it here is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s roster dashboard now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/preset/{presetId}/copy', name: self::PRESET_COPY_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 3)]
    public function copyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->copyPreset($request, RosterWidgets::declaration(), $presetId, $area->getUuid()),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/presets', name: self::PRESET_CREATE_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function createPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->createCustomPreset($request, RosterWidgets::declaration(), $area->getUuid()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/presets/{presetUuid}/apply', name: self::PRESET_APPLY_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function applyCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyCustomPreset($request, RosterWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Your own design is now this area’s roster dashboard.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/presets/{presetUuid}/rename', name: self::PRESET_RENAME_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function renameCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->renameCustomPreset($request, RosterWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/presets/{presetUuid}/delete', name: self::PRESET_DELETE_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function deleteCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->deleteCustomPreset($request, RosterWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Design deleted. Your dashboard is back on the one this module ships with.',
        );
    }

    /**
     * A refused write is returned as it came (the library's fetch reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, AreaOfInterest $area, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate(self::LIBRARY_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }
}
