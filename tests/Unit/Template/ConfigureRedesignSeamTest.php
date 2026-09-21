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

namespace Uhifadhi\Roster\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternNamer;

/**
 * THE CONFIGURE REDESIGN'S SEAMS — every name that crosses from Twig into
 * JavaScript, and the one DERIVATION that exists in both languages.
 *
 * A FULLY GREEN FUNCTIONAL SUITE PROVES NOTHING ABOUT EITHER. Nothing that
 * talks HTTP can catch a controller that reads `data-x` while the template
 * writes `data-y`: every server-side test passes and only a browser meets
 * the dead control. So the assertion is made on the FILES, as text, and it
 * fails where somebody would be editing.
 *
 * AND THE DERIVATION IS THE LOAD-BEARING HALF. A pattern's name is derived
 * by PHP on every read and by the browser on every keystroke, and the two
 * must be one rule: a sentence that says "2 days of night" while the saved
 * register says something else is a page that lies about what pressing Save
 * will do. Only the shape can be pinned from here — a PHP test cannot run
 * the JS — so the JS is asserted to spell the same three pieces, and the
 * PHP is asserted against the same cases below.
 */
final class ConfigureRedesignSeamTest extends TestCase
{
    private const string PATTERN_EDITOR = __DIR__.'/../../../assets/controllers/pattern_editor_controller.js';

    private const string STATION_TABLE = __DIR__.'/../../../assets/controllers/station_table_controller.js';

    private const string PATTERNS_PAGE = __DIR__.'/../../../templates/configure/patterns.html.twig';

    private const string WATCHES_PAGE = __DIR__.'/../../../templates/configure/watches.html.twig';

    private const string PACKAGE = __DIR__.'/../../../assets/package.json';

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path.' must be readable.');

        return $contents;
    }

    /**
     * THE CONTROLLER NAME IS ONE STRING IN THREE PLACES — the template that
     * mounts it, every action that calls it, and the manifest that registers
     * it. A name that agrees with only two of them is a `data-controller`
     * Stimulus never matches: markup that looks perfect and does nothing.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function controllers(): iterable
    {
        yield 'the sentence editor' => ['roster--pattern-editor', self::PATTERNS_PAGE];
        yield 'the station table' => ['roster--station-table', self::WATCHES_PAGE];
    }

    #[DataProvider('controllers')]
    public function testEachControllerIsNamedTheSameInTheTemplateAndTheManifest(string $name, string $page): void
    {
        $template = self::read($page);
        $manifest = self::read(self::PACKAGE);

        self::assertStringContainsString('data-controller="'.$name.'"', $template);
        self::assertStringContainsString('"name": "'.$name.'"', $manifest);
    }

    /**
     * EVERY ACTION THE TEMPLATE CALLS IS A METHOD THE CONTROLLER HAS. A
     * `data-action` naming a method that does not exist is a control that
     * silently does nothing, and Stimulus says so only in a browser console
     * nobody in CI is reading.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function actions(): iterable
    {
        yield 'the editor adds a part' => ['roster--pattern-editor#add', self::PATTERNS_PAGE, self::PATTERN_EDITOR];
        yield 'the editor drops a part' => ['roster--pattern-editor#drop', self::PATTERNS_PAGE, self::PATTERN_EDITOR];
        yield 'the editor re-derives' => ['roster--pattern-editor#render', self::PATTERNS_PAGE, self::PATTERN_EDITOR];
        yield 'a station runs a shift' => ['roster--station-table#toggle', self::WATCHES_PAGE, self::STATION_TABLE];
        yield 'a station is given its own rule' => ['roster--station-table#except', self::WATCHES_PAGE, self::STATION_TABLE];
        yield 'and follows the area again' => ['roster--station-table#forget', self::WATCHES_PAGE, self::STATION_TABLE];
    }

    #[DataProvider('actions')]
    public function testEveryActionTheTemplateCallsIsAMethodTheControllerHas(string $action, string $page, string $script): void
    {
        [, $method] = explode('#', $action);

        self::assertStringContainsString($action, self::read($page), 'The template calls it.');
        self::assertMatchesRegularExpression(
            '/\b'.preg_quote($method, '/').'\s*\(/',
            self::read($script),
            \sprintf('And %s() is a method the controller actually has.', $method),
        );
    }

    /**
     * EVERY HOOK IS WRITTEN ON BOTH SIDES. These are the attributes the two
     * controllers read; a template that stopped writing one would leave a
     * control that finds nothing to change.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function hooks(): iterable
    {
        foreach (['data-roster-cycle', 'data-roster-name', 'data-roster-length', 'data-roster-strip', 'data-roster-part', 'data-roster-days', 'data-roster-shift', 'data-roster-unit'] as $hook) {
            yield 'the editor reads '.$hook => [$hook, self::PATTERNS_PAGE, self::PATTERN_EDITOR];
        }

        foreach (['data-roster-expects', 'data-roster-exceptions', 'data-roster-exception-template', 'data-roster-field'] as $hook) {
            yield 'the table reads '.$hook => [$hook, self::WATCHES_PAGE, self::STATION_TABLE];
        }
    }

    #[DataProvider('hooks')]
    public function testEveryHookIsWrittenOnBothSidesOfTheSeam(string $hook, string $page, string $script): void
    {
        self::assertStringContainsString($hook, self::read($page), 'The template writes it.');
        self::assertStringContainsString($hook, self::read($script), 'And the controller reads it.');
    }

    /**
     * THE FIELD NAMES THE BROWSER STAMPS ONTO A NEW EXCEPTION ARE THE ONES
     * THE SERVER READS. The template cannot write them — one shape is
     * stamped onto twelve rows and only the row knows its station — so the
     * script builds them, which is exactly the kind of string that drifts.
     *
     * @return iterable<string, array{string}>
     */
    public static function exceptionFields(): iterable
    {
        yield 'which rule' => ['kind'];
        yield 'how much' => ['value'];
        yield 'in what' => ['unit'];
    }

    #[DataProvider('exceptionFields')]
    public function testTheScriptStampsTheFieldNamesTheControllerReads(string $field): void
    {
        self::assertStringContainsString(
            '`exception_${station}_'.$field.'[]`',
            self::read(self::STATION_TABLE),
            'The script names the field this way.',
        );
        self::assertStringContainsString(
            "\$prefix.'_".$field."'",
            self::read(__DIR__.'/../../../src/Controller/RosterConfigureController.php'),
            'And the controller reads that exact name.',
        );
    }

    /**
     * THE NAME IS DERIVED THE SAME WAY IN BOTH LANGUAGES. Three pieces, and
     * all three have to be spelled identically or the sentence and the
     * register disagree the moment somebody presses Save.
     *
     * @return iterable<string, array{string}>
     */
    public static function derivationPieces(): iterable
    {
        yield 'so many off' => ['${part.days} off'];
        yield 'one day of a shift' => ["1 === part.days ? 'day' : 'days'"];
        yield 'and of which shift' => ['} of ${label}'];
    }

    #[DataProvider('derivationPieces')]
    public function testTheBrowserDerivesTheNameTheSameWayThePhpDoes(string $piece): void
    {
        self::assertStringContainsString($piece, self::read(self::PATTERN_EDITOR));
    }

    /**
     * AND THE PHP SIDE OF THAT SAME RULE, on the same cases, so the two
     * halves of this test are read together.
     */
    public function testThePhpDerivationIsTheRuleTheBrowserSpells(): void
    {
        self::assertSame(
            '2 days of day, 1 day of night, 3 off',
            PatternNamer::derive(
                Cycle::of(['day', 'day', 'night', Cycle::OFF, Cycle::OFF, Cycle::OFF]),
                ['day' => 'day', 'night' => 'night'],
            ),
        );
    }

    /**
     * A SHIFT NAME IS NEVER PLURALISED, in either language. The words are
     * the area's own, the product does not know one from another, and it
     * will not be taught English — which is the whole reason the long form
     * was chosen over the short one.
     */
    public function testNeitherSidePluralisesAShiftName(): void
    {
        self::assertSame(
            '3 days of radio night, 2 off',
            PatternNamer::derive(Cycle::of(['radio night', 'radio night', 'radio night', Cycle::OFF, Cycle::OFF]), []),
        );

        $script = self::read(self::PATTERN_EDITOR);
        self::assertStringNotContainsString("label + 's'", $script);
        self::assertStringNotContainsString('${label}s', $script);
    }

    /**
     * THE PALETTE IS PICKED FROM, NOT CYCLED THROUGH, and it is the
     * entity's own eighteen.
     *
     * RULED 21 sep, option: a shift wears a SLOT and never a colour, so
     * the row opens the whole palette and shows what is left. There is no
     * script: the picker is the house dropdown, which is a `<details>`,
     * and choosing a slot is the same submit that saves the names and the
     * hours — a control that needed JavaScript to set a stored value is a
     * control that does nothing when the script fails to load.
     */
    public function testTheColourIsPickedFromTheHousesWholePalette(): void
    {
        $page = self::read(self::WATCHES_PAGE);

        self::assertStringContainsString('class="i-dd wpick"', $page, 'The picker is the house dropdown.');
        self::assertStringContainsString('class="wsl', $page, 'And every slot is a control of its own.');
        self::assertStringContainsString('for slot, wornBy in slots', $page, 'Over the whole palette, not the free part of it.');
        self::assertStringContainsString('name="shift_colour_{{ id }}" value="{{ slot }}"', $page, 'Choosing one posts it.');
        self::assertStringNotContainsString('type="color"', $page, 'Never a free colour.');
        self::assertStringNotContainsString('roster--station-table#recolour', $page, 'And never a cycle through the slots.');

        // THE ENTITY OWNS HOW MANY THERE ARE; the page asks it.
        self::assertStringContainsString('slots|length', $page);
        self::assertSame(18, Shift::SLOTS);
    }

    /**
     * AND THE CUT CHECKBOX STAYS CUT. Ruled 21 sep: a gap is on the sheet
     * the moment it exists, always, with no setting — "raise unfilled" says
     * only when it is RAISED as needing a decision. The first drawing put a
     * "say it sooner" tick beside it; a port that brought it back would be
     * shipping a setting the ruling removed.
     */
    public function testTheSayItSoonerCheckboxIsNotOnThePage(): void
    {
        $page = self::read(self::WATCHES_PAGE);

        self::assertStringNotContainsString('class="wchk"', $page, 'The checkbox the first drawing carried.');
        self::assertStringNotContainsString('type="checkbox"', $page, 'And no checkbox of any other name took its place.');
        self::assertStringContainsString('CUT, ruled 21 sep', $page, 'The page\'s own manifest says why it is missing.');
    }
}
