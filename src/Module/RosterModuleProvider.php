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

namespace Uhifadhi\Roster\Module;

use Uhifadhi\Contracts\ModulePermission;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;

/**
 * DECLARES THE ONE MODULE THIS BUNDLE CONTRIBUTES — "Roster": who is due on
 * watch in an area, where and when.
 *
 * THE SLUG IS SINGULAR, AND IT IS LOAD-BEARING. `roster` is the word in the
 * url every screen lives under (/areas/{uuid}/modules/roster), the key the
 * per-area ledger switches this module on by, and the slug every contribution
 * this module makes to the area overview has to repeat — a contribution whose
 * slug disagrees is a contribution that never disappears when an area switches
 * the module off. The pre-ruling scaffold said `rosters`; the ruling says
 * `roster`, and the catalogue row it replaces was called "Operations", a name
 * that collided with the operational-modules tier and is retired.
 *
 * WHAT IT OWNS, AND THE LINE IT DOES NOT CROSS. The rotation, the duties it
 * generates, the swap, the absence, and the watch a station expects. NOT the
 * station, NOT the posting, NOT the check-in, and NOT a position — those are
 * the AREA's, and presence is READ from the area's seam rather than computed
 * here. A roster says who is due at a post tonight; it never says who is
 * there.
 */
final class RosterModuleProvider implements ModuleProviderInterface
{
    // The defaults for status, pinned, base and position.
    use ModuleProviderTrait;

    /**
     * The one spelling of the module's identity, imported by every
     * contribution this module makes so no two can disagree about which
     * module they belong to.
     */
    public const string SLUG = 'roster';

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Roster';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'Rotations, duties and the area\'s check-ins';
    }

    public function icon(): string
    {
        return 'calendar-clock';
    }

    /**
     * THE MODULE OWNS ITS PAGES, so the tile links straight to the Overview
     * tab rather than through the platform's generic module page.
     */
    public function entryRoute(): string
    {
        return RosterController::OVERVIEW_ROUTE;
    }

    /**
     * DECLARED, NEVER GRANTED. The module says the permission exists and what
     * holding it lets a person do; Team folds it into the catalogue for
     * admins to assign, and it vanishes with the module on uninstall. This
     * module names no default holder and maps to no role.
     *
     * The value is the controller's own constant and not a retyped string: a
     * permission whose declaration and whose check differ by one character is
     * a screen nobody can open and a checkbox that grants nothing.
     */
    public function permissions(): array
    {
        return [
            new ModulePermission(
                RosterConfigureController::MANAGE_PERMISSION,
                'Roster',
                'Manage',
                'Change how this area runs its roster: the rotations, what each post’s watch expects, and the module’s settings.',
            ),
        ];
    }
}
