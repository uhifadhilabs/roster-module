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

use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Module\RosterModuleProvider;

/**
 * WHERE ROSTER DATA LIVES — the module's data places, and the whole of what it
 * says about its own navigation.
 *
 * SIX TABS ARE RULED: Overview · Today · Week · Day board · Calendar · Live,
 * with Configure as an action in the header and never a tab, exactly as on the
 * area's own tabs. The list here GROWS AS EACH PAGE LANDS, and that is
 * deliberate rather than a placeholder: a ModuleTab carries a route and the
 * shell generates a url from it, so a tab declared before its page exists is a
 * strip entry that 404s — worse than a strip that is still filling up. The
 * five that are not here yet are named in this docblock so nobody has to go
 * looking for the ruling.
 *
 * THE SHELL DRAWS BOTH RENDERINGS. The strip under the page head and the
 * module's children in the sidebar's tree come from this one list, so the two
 * cannot drift the way two hand-kept copies eventually would — which is why
 * this module ships no tab partial of its own.
 */
final readonly class RosterModuleTabs implements ModuleTabsInterface
{
    public function slug(): string
    {
        return RosterModuleProvider::SLUG;
    }

    public function tabs(): array
    {
        return [
            new ModuleTab('Overview', RosterController::OVERVIEW_ROUTE),
            new ModuleTab('Today', RosterController::TODAY_ROUTE),
            new ModuleTab('Week', RosterController::WEEK_ROUTE),
            new ModuleTab('Day board', RosterController::BOARD_ROUTE),
            new ModuleTab('Calendar', RosterController::CALENDAR_ROUTE),
            new ModuleTab('Live', RosterController::LIVE_ROUTE),
        ];
    }
}
