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
 * A RAIL WIDGET IS A LIST, AND `.fg-lst` IS THE WIDGET ROOT — ruled.
 *
 * THE SAME MARKUP IS RENDERED IN TWO PLACES: in the rail beside the Live
 * plate, and at full size in the widget library where somebody is choosing
 * between lists they have not seen. A rule a list needs that is scoped to
 * `.fg-side` applies in the first place and not the second, and the library
 * then shows the list as raw text — which is not a broken widget, it is an
 * unstyled one, and nothing errors.
 *
 * SO THE SCOPE IS THE ASSERTION. What belongs to the RAIL — its head, its
 * preset strip, its scroller, its foot, the cell chrome it wraps a list in —
 * may name `.fg-side`, because none of it means anything anywhere else. What
 * a LIST draws may not.
 */
final class RailListRulesBelongToTheListTest extends TestCase
{
    private const string SHEET = __DIR__.'/../../../public/roster.css';

    /**
     * The vocabulary a list draws with. Every one of these appears in the
     * three list partials and in nothing the rail owns.
     */
    private const array LIST_VOCABULARY = ['grp', 'pr', 'fg-stn', 'fg-zn', 'zsw', 'lv', 'loc', 'rl-more', 'cv'];

    /** The rail's own chrome, which is allowed to be scoped to the rail. */
    private const array RAIL_CHROME = ['fg-rlhd', 'rl-presets', 'rl-body', 'fg-foot', 'rl-cell', 'rl-cellhd', 'rl-add', 'rm', 'ord'];

    public function testNoRuleAListNeedsIsScopedToTheRail(): void
    {
        $offenders = [];

        foreach ($this->selectors() as $selector) {
            if (!str_contains($selector, '.fg-side')) {
                continue;
            }

            // A selector naming any of the rail's own chrome is about the
            // rail, whatever else it goes on to name.
            foreach (self::RAIL_CHROME as $chrome) {
                if (str_contains($selector, '.'.$chrome)) {
                    continue 2;
                }
            }

            foreach (self::LIST_VOCABULARY as $class) {
                if (preg_match('/\.'.preg_quote($class, '/').'\b/', $selector)) {
                    $offenders[] = $selector;

                    continue 2;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These rules style a LIST but are scoped to the RAIL, so the library twin loses them:\n  ".implode("\n  ", $offenders),
        );
    }

    /** AND THE LIST'S OWN ROOT IS STYLED, so the twin is a column either way. */
    public function testTheListRootIsStyledInItsOwnRight(): void
    {
        self::assertNotSame([], array_filter(
            $this->selectors(),
            static fn (string $selector): bool => '.fg-lst' === trim($selector),
        ), 'A widget root nothing styles is a widget that only looks right inside something else.');
    }

    /** @return list<string> every selector in the sheet, comments stripped. */
    private function selectors(): array
    {
        $css = file_get_contents(self::SHEET);
        self::assertIsString($css);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $selectors = [];
        foreach (explode('}', $css) as $block) {
            $at = strrpos($block, '{');
            if (false === $at) {
                continue;
            }

            foreach (explode(',', substr($block, 0, $at)) as $selector) {
                $selector = trim($selector);
                if ('' !== $selector && !str_starts_with($selector, '@')) {
                    $selectors[] = $selector;
                }
            }
        }

        return $selectors;
    }
}
