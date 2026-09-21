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

/**
 * THE SHEET'S SEAMS — every name that crosses from Twig into JavaScript,
 * from JavaScript into CSS, and from the template into a route.
 *
 * A FULLY GREEN FUNCTIONAL SUITE PROVES NOTHING ABOUT ANY OF THEM. Nothing
 * that talks HTTP can catch a controller that toggles `.closed` while the
 * sheet is written `.shut`, or a target the template never marks: every
 * server-side test passes and only a browser meets the dead control. So the
 * assertion is made on the FILES, as text, and it fails where somebody would
 * be editing.
 *
 * FOUR CONTROLLERS AND THREE SEAMS EACH — its name, its targets, and the
 * classes it toggles. If a name here has to change it changes in three
 * places at once, which is the point.
 */
final class SheetSeamTest extends TestCase
{
    private const string SHEET = __DIR__.'/../../../assets/controllers/sheet_controller.js';

    private const string FOLDS = __DIR__.'/../../../assets/controllers/sheet_folds_controller.js';

    private const string MENU = __DIR__.'/../../../assets/controllers/day_menu_controller.js';

    private const string FILL = __DIR__.'/../../../assets/controllers/fill_controller.js';

    private const string PAGE = __DIR__.'/../../../templates/week/show.html.twig';

    private const string CELL = __DIR__.'/../../../templates/week/_cell.html.twig';

    private const string PACKAGE = __DIR__.'/../../../assets/package.json';

    private const string SHEET_CSS = __DIR__.'/../../../public/roster.css';

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path.' must be readable.');

        return $contents;
    }

    /**
     * THE CONTROLLER NAME IS ONE STRING IN THREE PLACES — the template that
     * mounts it, the manifest that registers it, and the file it points at.
     * A name that agrees with only two of them is a `data-controller`
     * Stimulus never matches: markup that looks perfect and does nothing.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function controllers(): iterable
    {
        yield 'the bounded sheet' => ['roster--sheet', 'controllers/sheet_controller.js'];
        yield 'the station folds' => ['roster--sheet-folds', 'controllers/sheet_folds_controller.js'];
        yield 'the by-hand menu' => ['roster--day-menu', 'controllers/day_menu_controller.js'];
        yield 'the fill row' => ['roster--fill', 'controllers/fill_controller.js'];
    }

    #[DataProvider('controllers')]
    public function testEachControllerIsNamedTheSameInTheTemplateAndTheManifest(string $name, string $file): void
    {
        $manifest = self::read(self::PACKAGE);
        $mounted = self::read(self::PAGE);

        self::assertStringContainsString('"name": "'.$name.'"', $manifest, 'The manifest must register the name the page mounts.');
        self::assertStringContainsString('"main": "'.$file.'"', $manifest);
        self::assertFileExists(__DIR__.'/../../../assets/'.$file);
        self::assertStringContainsString($name, $mounted, 'The page must mount it.');
    }

    /**
     * EVERY TARGET A CONTROLLER DECLARES IS MARKED IN THE MARKUP, and every
     * one the markup marks is declared. A `hasXTarget` that is always false
     * is a feature that silently does nothing.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function targets(): iterable
    {
        yield 'the bounded sheet' => [self::SHEET, self::PAGE, ['scroller', 'today']];
        yield 'the fill row' => [self::FILL, self::PAGE, ['pattern', 'strip']];
    }

    /**
     * @param list<string> $targets
     */
    #[DataProvider('targets')]
    public function testEveryTargetIsBothDeclaredAndMarked(string $controller, string $page, array $targets): void
    {
        $js = self::read($controller);
        $template = self::read($page);

        preg_match('/static targets = \[([^\]]*)\]/', $js, $declared);
        $names = $declared[1] ?? '';
        self::assertNotSame('', $names, 'The controller must declare its targets.');

        foreach ($targets as $target) {
            self::assertStringContainsString("'".$target."'", $names, $target.' is marked in the markup and not declared.');
            self::assertMatchesRegularExpression('/-target="'.$target.'"/', $template, $target.' is declared and never marked.');
        }
    }

    /**
     * EVERY ACTION THE MARKUP CALLS IS A METHOD ON THE CONTROLLER IT NAMES.
     * This is the failure the whole class exists for: `#foldAll` against a
     * `foldEverything()` is a chip that looks right and does nothing.
     */
    public function testEveryActionTheMarkupCallsExists(): void
    {
        $methods = [
            'roster--sheet-folds' => self::read(self::FOLDS),
            'roster--day-menu' => self::read(self::MENU),
            'roster--fill' => self::read(self::FILL),
        ];

        foreach ([self::PAGE, self::CELL] as $page) {
            preg_match_all('/data-action="([^"]+)"/', self::read($page), $found);

            foreach ($found[1] as $action) {
                foreach (explode(' ', $action) as $one) {
                    if (!str_contains($one, '#')) {
                        continue;
                    }

                    [$controller, $method] = explode('#', str_contains($one, '->') ? explode('->', $one)[1] : $one, 2);

                    self::assertArrayHasKey($controller, $methods, $one.' names a controller this page does not mount.');
                    self::assertMatchesRegularExpression(
                        '/\b'.preg_quote($method, '/').'\s*\(/',
                        $methods[$controller],
                        $one.' is called and the controller has no such method.',
                    );
                }
            }
        }
    }

    /**
     * THE FOLD'S VALUES ARE READ ON BOTH SIDES. Stimulus turns
     * `urlValue` into `data-roster--sheet-folds-url-value`, and nothing
     * warns when only one half exists — the preference simply stops being
     * remembered, silently.
     */
    public function testTheFoldControllerReadsTheValuesTheMarkupWrites(): void
    {
        $js = self::read(self::FOLDS);
        $template = self::read(self::PAGE);

        foreach (['url', 'token'] as $value) {
            self::assertStringContainsString($value.': String', $js);
            self::assertStringContainsString('data-roster--sheet-folds-'.$value.'-value=', $template);
        }
    }

    /**
     * THE CLASSES THE CONTROLLERS TOGGLE ARE CLASSES THE SHEET SHIPS. A
     * class toggled by JavaScript and defined by nobody is a control that
     * runs perfectly and changes nothing on the screen.
     */
    public function testEveryClassTheControllersToggleIsShipped(): void
    {
        $css = self::read(self::SHEET_CSS);

        foreach (['shut', 'on'] as $toggled) {
            self::assertStringContainsString('.'.$toggled, $css, '.'.$toggled.' is toggled and shipped by nobody.');
        }

        // The selectors the controllers reach with, in the markup and the
        // sheet alike.
        foreach (['.stfold', '.strow', '.pmenuwrap', '.psheetwrap', '.sheetcard'] as $selector) {
            self::assertStringContainsString($selector, $css, $selector.' is reached for and shipped by nobody.');
            self::assertStringContainsString(
                ltrim($selector, '.'),
                self::read(self::PAGE).self::read(self::CELL),
                $selector.' is styled and never drawn.',
            );
        }
    }

    /**
     * AND THE BOUND THE CARD MEASURES IS THE ONE THE SHEET SPENDS.
     * `--sheetmax` written by the controller and read by nothing would
     * leave the sheet the height of its data — which is the one thing a
     * bounded card may never be.
     */
    public function testTheMeasuredBoundIsTheOneTheSheetSpends(): void
    {
        self::assertStringContainsString("setProperty('--sheetmax'", self::read(self::SHEET));
        self::assertStringContainsString('var(--sheetmax', self::read(self::SHEET_CSS));
    }

    /**
     * THE BY-HAND MENU IS PORTALLED OUT OF THE SHEET, and this is the
     * assertion that keeps it there.
     *
     * The sheet is a scroller with a sticky column head, a sticky ranger
     * column at four weeks, and an isolated `<tbody>` so a positioned day
     * cell cannot paint over the row saying which day it is. Every one of
     * those is a STACKING CONTEXT, and a menu opened inside them is
     * trapped underneath — which is how this menu came to open BEHIND the
     * day cells. No z-index wins an argument with an ancestor stacking
     * context, so the only fix is to leave.
     */
    public function testTheMenuLeavesTheSheetToOpen(): void
    {
        $js = self::read(self::MENU);

        self::assertStringContainsString('document.body.appendChild(menu)', $js, 'The menu has to leave the sheet, not out-rank it.');
        self::assertStringContainsString('getBoundingClientRect()', $js, 'And be placed from the cell it belongs to.');
        self::assertStringContainsString("classList.add('pmout')", $js);
        self::assertStringContainsString("classList.remove('pmout')", $js);
        self::assertStringContainsString('.pmenu.pmout', self::read(self::SHEET_CSS), 'The portalled state has to be shipped.');
        self::assertStringContainsString('position: fixed', self::read(self::SHEET_CSS));
    }

    /**
     * AND IT IS PUT BACK, never cloned. The template is the one place the
     * menu exists; a controller that copied it would answer a form the
     * second copy had already answered, and one that forgot to return it
     * would strand a node on the body after a navigation.
     */
    public function testTheMenuIsReturnedToItsCellAndNeverCloned(): void
    {
        $js = self::read(self::MENU);

        self::assertStringContainsString('this.home.appendChild(this.open_)', $js, 'Closing puts it back.');
        self::assertStringContainsString('this.close();', $js);
        self::assertMatchesRegularExpression('/disconnect\(\)\s*\{\s*(?:\/\/[^
]*
\s*)*this\.close\(\);/', $js, 'And so does going away.');
        self::assertStringNotContainsString('cloneNode', $js);
    }

    /**
     * BEING FIXED, IT CANNOT FOLLOW THE CELL — so anything that moves the
     * cell closes it. A menu left hanging over a scrolled sheet points at
     * a day that is no longer under it.
     */
    public function testAnythingThatMovesTheCellClosesTheMenu(): void
    {
        $js = self::read(self::MENU);

        self::assertStringContainsString("addEventListener('scroll', this.away, true)", $js, 'Capture, so the sheet\'s own scroller counts.');
        self::assertStringContainsString("addEventListener('resize', this.away)", $js);
        self::assertStringContainsString("'Escape'", $js);
        self::assertStringContainsString("addEventListener('click', this.dismiss)", $js);
    }

    /**
     * THE PORTALLED MENU OUT-RANKS EVERY LAYER THE SHEET PINS — read out
     * of the sheet rather than typed here, so a new sticky layer raised
     * above it fails this test instead of hiding the menu again.
     *
     * AND IT STAYS UNDER THE SHELL'S CONFIRM MODAL (120), which is the one
     * thing that must always win: a question about destroying something
     * cannot be covered by the menu that asked it.
     */
    public function testThePortalledMenuOutRanksEverySheetLayer(): void
    {
        $css = self::read(self::SHEET_CSS);

        preg_match('/\.pmenu\.pmout\s*\{[^}]*z-index:\s*(\d+)/', $css, $portal);
        $declared = $portal[1] ?? '';
        self::assertNotSame('', $declared, 'The portalled menu has to declare the layer it opens on.');
        $top = (int) $declared;

        // Every z-index the sheet's own sticky and isolated layers spend.
        preg_match_all('/(\.psheetwrap|table\.csheet|\.cl)[^{}]*\{[^}]*z-index:\s*(\d+)/', $css, $layers);
        self::assertNotSame([], $layers[2], 'The sheet pins layers; this test is about beating them.');

        foreach ($layers[2] as $layer) {
            self::assertGreaterThan((int) $layer, $top, 'A sheet layer is pinned above the menu, which is how it opened behind the cells.');
        }

        self::assertLessThan(120, $top, 'The shell\'s confirm modal has to stay on top of everything.');

        // The controller sets the same number inline, because a portalled
        // element has left the cascade this rule lives in.
        self::assertStringContainsString('PORTAL_Z = '.$top, self::read(self::MENU), 'The inline z-index and the sheet\'s must be one number.');
    }

    /**
     * THE CELL READS THE SHIFT'S STORED SLOT AND NEVER A HUE OF ITS OWN.
     * RULED 21 sep: a shift is given a colour when it is created and keeps
     * it on every tab, so the cell resolves `[data-cat]` through the
     * shell's own resolver.
     */
    public function testTheCellTakesItsColourFromTheStoredSlot(): void
    {
        $cell = self::read(self::CELL);
        $css = self::read(self::SHEET_CSS);

        self::assertStringContainsString('data-cat="{{ cell.colour }}"', $cell);
        self::assertStringContainsString('var(--cat', $css, 'The bar reads the resolved slot.');
        self::assertDoesNotMatchRegularExpression('/\.cl\.bar[^{]*\{[^}]*#[0-9a-f]{3,6}/i', $css, 'No hex on the cell: the colour is the shift\'s.');
    }
}
