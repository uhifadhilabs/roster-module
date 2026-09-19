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

namespace Uhifadhi\Roster\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterWidgetsController;
use Uhifadhi\Roster\Module\RosterModuleProvider;

/**
 * WHAT IS ON THE ROSTER'S CONFIGURE PAGE — the rotation, what each post's
 * watch expects, and the module's own settings. Configuration only: the
 * figures live on the tabs.
 *
 * ALL THREE KEEP AN ADDRESS OF THEIR OWN, and the STYLESHEET is the reason
 * rather than taste. A section the shell renders as a BODY inside its own
 * configure page can spend only the vocabulary the SHELL's sheet ships: that
 * page links the shell's sheet and no module's, and it is not the shell's
 * business to know which sheets a module's section needs. Every one of these
 * three draws this module's own families — the banded card, the cycle ring,
 * the generated month, the watch rows, the status list — so each is declared
 * as {@see ConfigurationSection::screen()}, keeps an address of its own, and
 * links what it draws. Each still belongs to the configure page: it wears the
 * page's heading and the page's strip, and the Configure action stays lit on
 * it, because that screen adopts the same frame.
 *
 * WIDGET LIBRARY IS FIRST, as it is on every configure page in the platform.
 * It is a section of THIS page and never a door on the dashboard: editing a
 * surface does not happen on the surface being edited.
 *
 * IT RESOLVES THE REQUEST ITSELF, like every other source in the frame: the
 * shell passes nothing, because it has a slug and not an area.
 */
final readonly class RosterConfigurationSections implements ConfigurationSectionsInterface
{
    /** How this area's roster dashboard is composed. */
    public const string WIDGETS = 'widgets';

    /** The rotation a post or a team runs. */
    public const string ROTATION = 'rotation';

    /** The four columns this module owns on a station. */
    public const string WATCHES = 'watches';

    public function __construct(
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
    ) {
    }

    public function slug(): string
    {
        return RosterModuleProvider::SLUG;
    }

    /**
     * The whole heading, to which the shell adds " · configure". Empty when
     * the request names no area — a page the configure route never serves,
     * but a source that answers only on the pages it expects is a source that
     * throws on the one it did not.
     */
    public function heading(): string
    {
        $area = $this->currentArea();

        return null === $area ? '' : $area->getName().' — Roster';
    }

    public function summary(): string
    {
        return 'The rotation, what each post’s watch expects, and the module’s own settings. Configuration only — the figures live on the tabs.';
    }

    public function sections(): array
    {
        $area = $this->currentArea();

        if (null === $area) {
            return [];
        }

        $uuid = (string) $area->getUuidString();

        return [
            ConfigurationSection::screen(self::WIDGETS, 'Widget library', RosterWidgetsController::LIBRARY_ROUTE, ['uuid' => $uuid]),
            ConfigurationSection::screen(self::ROTATION, 'Rotation', RosterConfigureController::ROTATION_ROUTE, ['uuid' => $uuid]),
            ConfigurationSection::screen(self::WATCHES, 'Watches', RosterConfigureController::WATCHES_ROUTE, ['uuid' => $uuid]),
            ConfigurationSection::screen(ConfigurationSection::SETTINGS, 'Settings', RosterConfigureController::SETTINGS_ROUTE, ['uuid' => $uuid]),
        ];
    }

    private function currentArea(): ?AreaOfInterest
    {
        $uuid = $this->requests->getCurrentRequest()?->attributes->get('uuid');

        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
