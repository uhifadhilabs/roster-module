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

namespace Uhifadhi\Roster\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;

/**
 * A MODULE SHIPS THE MARKS IT ASKS FOR.
 *
 * A BARE ICON NAME RESOLVES IN THE HOST'S DEFAULT SET, so a module that
 * writes one is betting that every installation happens to ship that glyph.
 * The bet fails SILENTLY until something renders the name somewhere that
 * has no such icon — which is how `calendar-clock` took a whole suite down
 * the first time the shell drew this module's row in a fixture app.
 *
 * SO EVERY NAME THIS MODULE WRITES IS NAMESPACED AND EVERY NAMESPACED NAME
 * IS SHIPPED. `roster:` is this bundle's own directory and travels with it;
 * `shell:` is the shell's and is fair game because the shell ships it.
 */
final class ShippedIconsTest extends TestCase
{
    private const string ICONS = __DIR__.'/../../../assets/icons/roster';

    private const array ROOTS = [__DIR__.'/../../../src', __DIR__.'/../../../templates'];

    /** Namespaces this module may name without shipping the file itself. */
    private const array THEIRS = ['shell', 'atlas', 'area', 'team'];

    public function testEveryIconThisModuleAsksForUnderItsOwnNamespaceIsShipped(): void
    {
        $missing = [];

        foreach ($this->asked() as $name => $where) {
            [$namespace, $icon] = explode(':', $name, 2);

            if (\in_array($namespace, self::THEIRS, true)) {
                continue;
            }

            self::assertSame('roster', $namespace, \sprintf('%s names the "%s" set, which is nobody\'s.', $where, $namespace));

            if (!is_file(self::ICONS.'/'.$icon.'.svg')) {
                $missing[] = \sprintf('%s (%s)', $name, $where);
            }
        }

        self::assertSame([], $missing, "These marks are asked for and not shipped, so they render as nothing wherever the host has no such icon:\n  ".implode("\n  ", $missing));
    }

    /**
     * AND NOTHING ASKS FOR A BARE NAME. The one that did reached the
     * shell's own nav, which renders it in whatever application has
     * mounted the module — including one with no icon directory at all.
     */
    public function testNothingAsksForAnIconWithoutSayingWhoseItIs(): void
    {
        $bare = [];

        foreach (self::ROOTS as $root) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/ux_icon\(\s*\'([^\':]+)\'/', $code, $matches)) {
                    foreach ($matches[1] as $name) {
                        $bare[] = \sprintf('%s in %s', $name, $file->getFilename());
                    }
                }
            }
        }

        self::assertSame([], $bare, "These name an icon without a set, so they resolve wherever the host happens to look:\n  ".implode("\n  ", $bare));
    }

    /**
     * Every namespaced icon name this module writes, by where it writes it.
     *
     * @return array<string, string>
     */
    private function asked(): array
    {
        $asked = [];

        foreach (self::ROOTS as $root) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/\'([a-z][a-z0-9-]*:[a-z][a-z0-9-]*)\'/', $code, $matches)) {
                    foreach ($matches[1] as $name) {
                        $asked[$name] = $file->getFilename();
                    }
                }
            }
        }

        return $asked;
    }
}
