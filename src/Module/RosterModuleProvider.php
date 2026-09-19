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

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

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
    // The defaults for status, pinned, base, position and permissions, and the
    // null entryRoute this module keeps until it owns a route of its own.
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
}
