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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Controller\RosterWidgetsController;

/**
 * EVERY URL THE SHELL'S LIBRARY COMPONENT NEEDS, named once.
 *
 * THE COMPONENT IS THE SHELL'S AND THE ROUTES ARE THIS MODULE'S, so
 * something has to join them. It is written here rather than in a template
 * because two screens hand the map over and a second copy would eventually
 * name one route the other did not.
 *
 * AREA-SCOPED, AND THAT IS STATED IN THE URL rather than trusted to a check:
 * arranging one area's roster dashboard can never rearrange another's.
 */
final readonly class RosterWidgetUrls
{
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * A template carries {@see WidgetDom::ID_PLACEHOLDER} where a preset's
     * id or uuid goes; the library's script substitutes into it, because a
     * preset card that only exists after a click has no server-rendered
     * href to read.
     *
     * @return array<string, string>
     */
    public function forArea(AreaOfInterest $area): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => (string) $area->getUuidString()];
        $url = fn (string $route, array $extra = []): string => $this->router->generate($route, [...$uuid, ...$extra]);

        return [
            'save' => $url(RosterWidgetsController::SAVE_ROUTE),
            'reset' => $url(RosterWidgetsController::RESET_ROUTE),
            'preset' => $url(RosterWidgetsController::PRESET_ROUTE, ['presetId' => $id]),
            'copy' => $url(RosterWidgetsController::PRESET_COPY_ROUTE, ['presetId' => $id]),
            'presets' => $url(RosterWidgetsController::PRESET_CREATE_ROUTE),
            'apply' => $url(RosterWidgetsController::PRESET_APPLY_ROUTE, ['presetUuid' => $id]),
            'rename' => $url(RosterWidgetsController::PRESET_RENAME_ROUTE, ['presetUuid' => $id]),
            'delete' => $url(RosterWidgetsController::PRESET_DELETE_ROUTE, ['presetUuid' => $id]),
            'dashboard' => $url(RosterController::OVERVIEW_ROUTE),
        ];
    }
}
