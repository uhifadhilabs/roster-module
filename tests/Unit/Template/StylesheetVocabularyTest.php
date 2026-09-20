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

use PHPUnit\Framework\TestCase;

/**
 * THE CONFORMANCE CHECK EVERY MODULE IN THE FLEET RUNS, and the one that
 * catches the bug four modules shipped independently.
 *
 * A class this module's templates use and NOBODY ships falls back to browser
 * defaults — black text, grey buttons, dashed borders — and a `var(--token)`
 * nothing defines falls back to the inherited colour. Both look perfect in a
 * standalone render of the design's own sheet and broken in a real
 * installation, because there the page loads the SHELL's sheet plus this
 * module's and nothing else.
 *
 * And the other direction, which is the subtler one: RESTATING a shared class
 * here means two copies loaded in some order, rendering differently — exactly
 * what "the same thing renders identically everywhere" forbids. If something
 * this module needs is missing from the shell's sheet, it is added THERE.
 *
 * It is a plain TestCase reading files as TEXT on purpose. Nothing that
 * renders HTML can catch either failure: both produce a page that is valid,
 * complete and wrong.
 */
final class StylesheetVocabularyTest extends TestCase
{
    private const string MODULE_SHEET = __DIR__.'/../../../public/roster.css';

    /**
     * THE ORGANISATION SHEET, which this module also ships and the org
     * pages link themselves. It is separate because only the three pages
     * at that scope need it, and it holds exactly the marks the wider
     * scope adds: which area a row is about.
     */
    private const string ORG_SHEET = __DIR__.'/../../../public/roster-org.css';

    private const string SHELL_SHEET = __DIR__.'/../../../vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/public/shell.css';

    /**
     * THE WIDGET GRID'S OWN SHEET, third in the chain the composed tab
     * loads. The shell ships it separately because only a page that
     * composes a surface needs it, and this module's base links it for
     * exactly that reason — so the vocabulary it defines is shipped on the
     * overview and has to count as shipped here.
     */
    private const string WIDGET_SHEET = __DIR__.'/../../../vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/public/widget.css';

    /**
     * THE PLATFORM'S ONE MAP SHEET, which the PAGE links rather than the
     * component. The calendar and the chart bring their own; the plate's
     * does not travel with it, so every page that draws one links this —
     * and its vocabulary counts as shipped on those pages.
     */
    private const string MAP_SHEET = __DIR__.'/../../../vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/public/map.css';

    private const string TEMPLATES = __DIR__.'/../../../templates';

    /**
     * Classes that are not vocabulary: they are hooks a stylesheet is not
     * expected to dress, or state modifiers only ever written beside a class
     * that IS dressed.
     *
     * @var list<string>
     */
    private const array NOT_VOCABULARY = [
        // Shell state/utility words that appear only as modifiers.
        'on', 'off', 'sm', 'closed', 'empty',
    ];

    /**
     * EVERY CLASS THE TEMPLATES SPEND IS SHIPPED BY SOMEBODY — this module's
     * sheet or the shell's, and nothing else is on the page.
     */
    public function testEveryClassTheTemplatesUseIsShippedBySomebody(): void
    {
        $shipped = self::selectorsIn(self::read(self::MODULE_SHEET))
            + self::selectorsIn(self::read(self::ORG_SHEET))
            + self::selectorsIn(self::read(self::SHELL_SHEET))
            + self::selectorsIn(self::read(self::WIDGET_SHEET))
            + self::selectorsIn(self::read(self::MAP_SHEET));

        $missing = [];
        foreach (self::classesUsedInTemplates() as $class => $files) {
            if (\in_array($class, self::NOT_VOCABULARY, true)) {
                continue;
            }

            if (!isset($shipped[$class])) {
                $missing[] = \sprintf('.%s (used in %s)', $class, implode(', ', $files));
            }
        }

        self::assertSame([], $missing, "These classes are spent by a template and shipped by nobody, so they render as browser defaults in a real installation:\n".implode("\n", $missing));
    }

    /**
     * THIS MODULE RESTATES NO SHARED CLASS **UNQUALIFIED**. Two rules for a
     * bare `.factband` loaded in either order render differently, and which
     * one wins depends on the order a page happens to link them.
     *
     * QUALIFIED IS A DIFFERENT THING AND IS ALLOWED. `.rb-foot .k` does not
     * restate the shell's `.k`; it says what a `.k` looks like INSIDE this
     * module's own footer strip, which is a rule only this module can own and
     * which cannot leak — there is no `.rb-foot` anywhere else. So the test
     * is about selectors that reach a shared class with nothing of this
     * module's in front of them.
     */
    public function testThisModuleRestatesNoSharedClassUnqualified(): void
    {
        $shell = self::selectorsIn(self::read(self::SHELL_SHEET))
            + self::selectorsIn(self::read(self::WIDGET_SHEET))
            + self::selectorsIn(self::read(self::MAP_SHEET));

        $restated = [];
        $ours = [...self::selectorListIn(self::read(self::MODULE_SHEET)), ...self::selectorListIn(self::read(self::ORG_SHEET))];

        foreach ($ours as $selector) {
            preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', $selector, $matches);
            $classes = $matches[1];

            if ([] === $classes) {
                continue;
            }

            // A selector that names anything of this module's is anchored in
            // this module and cannot reach another component's markup.
            foreach ($classes as $class) {
                if (self::isOurs($class)) {
                    continue 2;
                }
            }

            foreach ($classes as $class) {
                if (isset($shell[$class])) {
                    $restated[] = $selector;

                    continue 2;
                }
            }
        }

        self::assertSame([], array_values(array_unique($restated)), "These selectors dress a shared class with nothing of this module's in front of them, so they fight the shell's own rule; qualify them or add what is missing to the shell's sheet:\n".implode("\n", array_unique($restated)));
    }

    /** Whether a class belongs to this module's own vocabulary. */
    private static function isOurs(string $class): bool
    {
        return str_starts_with($class, 'r-')
            || str_starts_with($class, 'rb-')
            // `fg-*` is this module's too: the rota and its cells graduated
            // out of the design's format gallery and kept their names, so
            // the app and the workspace stay in step.
            || str_starts_with($class, 'fg-')
            // `org*` is this module's organisation vocabulary, declared in
            // its own sheet and written only in its own org templates. It is
            // named for the SCOPE rather than for the module because that is
            // what the marks are about — which area a row is — and a second
            // module at that scope will want the same three, at which point
            // they graduate to the shell rather than being copied.
            || str_starts_with($class, 'org')
            || \in_array($class, ['rband', 'rset', 'rsw', 'rfrag', 'rstat', 'lfilt-n'], true);
    }

    /**
     * EVERY TOKEN THIS SHEET SPENDS IS DEFINED — here, or by the shell, which
     * is the only other sheet on the page.
     */
    public function testEveryTokenThisSheetSpendsIsDefinedBySomebody(): void
    {
        $mine = self::read(self::MODULE_SHEET);
        $defined = self::tokensDefinedIn($mine)
            + self::tokensDefinedIn(self::read(self::SHELL_SHEET))
            + self::tokensDefinedIn(self::read(self::WIDGET_SHEET))
            + self::tokensDefinedIn(self::read(self::MAP_SHEET));

        $spent = [];
        preg_match_all('/var\(\s*(--[a-z0-9_-]+)/i', $mine, $matches);
        foreach ($matches[1] as $token) {
            if (!isset($defined[$token])) {
                $spent[] = $token;
            }
        }

        self::assertSame([], array_values(array_unique($spent)), 'These tokens are spent by roster.css and defined by nobody, so they fall back to the inherited value: '.implode(', ', array_unique($spent)));
    }

    /**
     * MODULES HAVE NO HUE — ruled 2026-09-20, and this is the check that
     * keeps it.
     *
     * A module is not a colour. This sheet once declared `--r-acc`, an
     * invented blue, and everything selected in the module wore it; the
     * result was a product where "on" meant one thing on a roster screen
     * and another everywhere else. Selected and on states wear the HOUSE
     * on-state, plates use the shell's plate palette, and the sidebar's
     * module dot is the accent.
     *
     * SO THE SHEET DECLARES NO CUSTOM PROPERTY AND CONTAINS NO LITERAL. Both
     * are how a module quietly acquires a palette of its own — a hex is the
     * obvious way and a `--r-*` token is the polite one, and the polite one
     * is worse because it looks deliberate.
     */
    public function testTheSheetDeclaresNoColourOfItsOwn(): void
    {
        $css = self::read(self::MODULE_SHEET);
        $body = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        $declared = [];
        preg_match_all('/(--[a-z0-9_-]+)\s*:/i', $body, $matches);
        foreach ($matches[1] as $token) {
            $declared[] = $token;
        }

        self::assertSame([], array_values(array_unique($declared)), "A module declares no token of its own; these belong in the shell's sheet:\n".implode("\n", array_unique($declared)));

        $literals = [];
        // A hex, an rgb()/hsl() call, or a bare colour keyword used as a
        // value. `transparent` and `currentColor` are not colours a module
        // chose — they are relationships — so they stay.
        preg_match_all('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|:\s*(?:white|black|red|green|blue|grey|gray|orange|yellow)\b/', $body, $found);
        foreach ($found[0] as $literal) {
            $literals[] = trim($literal);
        }

        self::assertSame([], array_values(array_unique($literals)), "A module names no colour of its own; use a shell token:\n".implode("\n", array_unique($literals)));
    }

    /**
     * THE DESIGN WORKSPACE'S OWN IDENTIFIERS ARE NOT PRODUCT. `RO·01`,
     * `RO·D2` and the rest are a referencing system for design discussion;
     * they were ported into a shipped template once and rendered live.
     * Checked in all three spellings a port can produce.
     */
    public function testNoWorkshopIdentifiersReachedAShippedTemplate(): void
    {
        $offenders = [];
        foreach (self::templateFiles() as $file) {
            $body = self::read($file);
            foreach (['·', '&middot;', '&#183;'] as $dot) {
                if (1 === preg_match('/\bRO\s*'.preg_quote($dot, '/').'\s*[0-9A-Z]/', $body)) {
                    $offenders[] = basename($file);
                }
            }
            if (str_contains($body, 'class="idx"')) {
                $offenders[] = basename($file).' (workshop index chip)';
            }
        }

        self::assertSame([], array_values(array_unique($offenders)), 'A design-workspace identifier reached a shipped template: '.implode(', ', array_unique($offenders)));
    }

    /**
     * @return array<string, list<string>> class => the templates that use it
     */
    private static function classesUsedInTemplates(): array
    {
        $used = [];
        foreach (self::templateFiles() as $file) {
            preg_match_all('/class="([^"{}]*)"/', self::read($file), $matches);
            foreach ($matches[1] as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                    if ('' !== $class) {
                        $used[$class][basename($file)] = true;
                    }
                }
            }
        }

        return array_map(static fn (array $files): array => array_keys($files), $used);
    }

    /**
     * @return list<string>
     */
    private static function templateFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'twig' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every class a sheet DEFINES a rule for, as a set.
     *
     * @return array<string, true>
     */
    private static function selectorsIn(string $css): array
    {
        // Comments first: a class named in prose is not a class that is
        // shipped, and one commented out is exactly the bug this looks for.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        $found = [];
        preg_match_all('/\.(-?[_a-zA-Z][\w-]*)(?=[^{}]*\{)/', $css, $matches);
        foreach ($matches[1] as $class) {
            $found[$class] = true;
        }

        return $found;
    }

    /**
     * EVERY SELECTOR A SHEET DECLARES A RULE FOR, whole and one per comma —
     * because the question "is this class dressed on its own" can only be
     * asked of a complete selector, never of the class names in it.
     *
     * @return list<string>
     */
    private static function selectorListIn(string $css): array
    {
        $css = preg_replace('#/\\*.*?\\*/#s', '', $css) ?? $css;

        $selectors = [];
        preg_match_all('/([^{}@]+)\\{/', $css, $matches);
        foreach ($matches[1] as $block) {
            foreach (explode(',', $block) as $selector) {
                $selector = trim(preg_replace('/\\s+/', ' ', $selector) ?? $selector);
                if ('' !== $selector && !str_starts_with($selector, '@')) {
                    $selectors[] = $selector;
                }
            }
        }

        return $selectors;
    }

    /**
     * @return array<string, true>
     */
    private static function tokensDefinedIn(string $css): array
    {
        $found = [];
        preg_match_all('/(--[a-z0-9_-]+)\s*:/i', $css, $matches);
        foreach ($matches[1] as $token) {
            $found[$token] = true;
        }

        return $found;
    }

    private static function read(string $path): string
    {
        $body = file_get_contents($path);
        self::assertIsString($body, $path.' is not readable.');

        return $body;
    }
}
