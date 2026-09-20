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

namespace Uhifadhi\Roster\Tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Tests\Integration\TestKernel;

/**
 * THE SUITE MAY NOT TEST YESTERDAY'S SHELL.
 *
 * EVERY TEMPLATE THIS MODULE RENDERS THROUGH BELONGS TO THE CORE — the page
 * frame, the widget library, the map plate — and Twig compiles them into the
 * kernel's cache directory once. A cache that outlives the package it
 * compiled makes the suite answer for a shell that is no longer installed:
 * green against the old one, red against the new, with nothing in the diff
 * to explain either.
 *
 * IT REALLY HAPPENED. A test for a section id the core had just started
 * emitting stayed red through a correct `composer update`, and the only
 * thing wrong was this directory. Debug mode does not save you: Twig's
 * auto-reload compares a template's own mtime, and a package Composer has
 * just extracted can carry an older one than the compiled copy beside it.
 *
 * So the core's reference is part of the path, and this holds it there.
 */
final class TestKernelCacheIsKeyedByTheCoreTest extends TestCase
{
    public function testTheCacheDirectoryNamesTheCoreItWasBuiltAgainst(): void
    {
        $reference = InstalledVersions::getReference('uhifadhi/uhifadhi');
        self::assertIsString($reference, 'This suite is run against a resolved core, not a guess.');

        self::assertStringContainsString(
            substr($reference, 0, 12),
            new TestKernel('test', true)->getCacheDir(),
            'A new core has to be a new cache, or the suite compiles the old one once and keeps it.',
        );
    }

    /** AND THE LOG SITS BESIDE IT, so one core leaves one directory behind. */
    public function testTheLogDirectorySitsUnderTheSameKey(): void
    {
        $kernel = new TestKernel('test', true);

        self::assertSame(\dirname($kernel->getCacheDir()), \dirname($kernel->getLogDir()));
        self::assertNotSame($kernel->getCacheDir(), $kernel->getLogDir());
    }
}
