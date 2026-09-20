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

namespace Uhifadhi\Roster\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE LIVE TAB'S PLATE RAIL, AS A SURFACE — a transcription of the design's
 * own declaration (roster.widgets.js, id `roster-live-rail`).
 *
 * THE COLUMN BESIDE THE PLATE IS NOT "THE PEOPLE LIST". It is the plate's
 * RAIL, and what it carries is composed the way every other surface in the
 * app is composed: the lists are WIDGETS, adopted as presets from this
 * module's own library. Ruled 20 sep as option E, against four alternatives
 * that were drawn and rejected.
 *
 * ONE COLUMN WIDE, SO EVERY WIDGET IS FULL WIDTH. `cols` is 12 for all
 * three and each offers only the one span — the widths a page surface
 * offers do not apply to a rail, and the framework is tolerant of that by
 * design rather than by accident.
 *
 * EVERY ROW CENTRES THE PLATE ON WHAT IT NAMES, and a row that cannot be
 * centred is not a link. That is a rule about the rows rather than about
 * this catalogue, but it is why all three widgets belong to one group: they
 * are three readings of one map, not three unrelated cards.
 *
 * IT IS A SECOND SURFACE AND NOT A PRESET OF THE FIRST. The Overview's
 * surface composes a page out of cards; this composes a column out of
 * lists. They share the mechanism — the same catalogue shape, the same
 * resolution, the same store — and nothing else, which is exactly what a
 * surface is for.
 */
final class RosterRailWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'roster-live-rail';

    /** What the arrangement this module ships with is CALLED. */
    public const string DEFAULT_LABEL = 'The duty officer';

    public const string DEFAULT_DESCRIPTION = 'What the Live tab’s rail ships with: the people on the roster by presence state, then the twelve posts. The zones are one adopt away.';

    /** The heading the library files all three lists under. */
    public const string GROUP = 'rail';

    /**
     * WHAT THE RAIL ITSELF CALLS EACH LIST.
     *
     * A three-hundred-pixel column has room for a word, and the library —
     * where somebody is choosing between lists they have not seen — has
     * room for a sentence. Both names are declared here so the head of a
     * cell and the door that puts it back cannot drift apart.
     */
    public const array NAMES = ['people' => 'People', 'stations' => 'Stations', 'zones' => 'Zones'];

    public function catalog(): WidgetCatalog
    {
        return self::declaration();
    }

    /** The catalogue, reachable without an instance. */
    public static function declaration(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [new WidgetGroup(
                self::GROUP,
                'Lists for the plate rail',
                'Each one is a list beside the plate, and each row centres the plate on what it names. Adopt a preset, or take one list out and another in — the rail is composed the way every other surface in the app is composed.',
            )],
            self::widgets(),
            self::presets(),
            // THE SHIPPED ARRANGEMENT IS ONE OF THE THREE, not a nameless
            // default: "The duty officer" is a design somebody chose, and
            // the strip leads with it under its own name.
            'duty',
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }

    /**
     * THE THREE LISTS. People and Stations ship on; Zones is one adopt
     * away, because eleven more rows on a rail that already carries thirty
     * is a wall rather than a reading — and the design says so by shipping
     * it off rather than by leaving it out.
     *
     * @return list<Widget>
     */
    private static function widgets(): array
    {
        return [
            new Widget('people', 'People by presence state', self::GROUP, 12, [12], on: true, note: 'The people on the roster, grouped by the state their check-in and their pings derive: at post verified, at post unverified, not at a post, no position, and the ones not on the watch today behind their own head.'),
            new Widget('stations', 'Stations', self::GROUP, 12, [12], on: true, note: 'One row per post — name, code, who is posted, how many of them are live now, and the one thing wrong with the answer. The ones with a watch first, the ones without after them.'),
            new Widget('zones', 'Zones', self::GROUP, 12, [12], on: false, note: 'One row per zone — its colour on the plate, its area, who is posted inside it and how many of them are live. The ones with somebody posted first.'),
        ];
    }

    /**
     * THE THREE ARRANGEMENTS, each with what it is FOR. A preset is a
     * decision about who is reading, and the description says which
     * question it answers fastest — the library is where somebody chooses
     * between them, and a list of layouts with no reason attached is a
     * list nobody can choose from.
     *
     * @return list<WidgetPreset>
     */
    private static function presets(): array
    {
        return [
            new WidgetPreset(
                'duty',
                self::DEFAULT_LABEL,
                'People first, posts under them. The arrangement for the person answering the radio: the name comes before the place, because the question is usually “where is J. Mollel”.',
                ['people' => 12, 'stations' => 12],
            ),
            new WidgetPreset(
                'posts',
                'Posts first',
                'The posts above the people. The arrangement for a shift handover, where the question is which posts are covered and which are silent, and the names are the detail underneath.',
                ['stations' => 12, 'people' => 12],
            ),
            new WidgetPreset(
                'everything',
                'Everything',
                'All three lists, people then posts then zones. The widest reading of the plate, and the only one that answers “which zone has nobody in it right now” without leaving the tab.',
                ['people' => 12, 'stations' => 12, 'zones' => 12],
            ),
        ];
    }
}
