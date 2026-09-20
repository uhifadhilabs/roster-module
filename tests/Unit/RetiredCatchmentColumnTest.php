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

use PHPUnit\Framework\TestCase;

/**
 * ONE DISTANCE, ONE HOME — and the retired column stays unread until it goes.
 *
 * A POST'S RING IS `station.catchment_m`. That is the column the AREA
 * measures a claim against and the one its own service writes; this module
 * states the distance and does no measuring with it. The watch carried the
 * same number in a second place for a while, and two columns for one
 * distance are two answers the day somebody edits one of them — which was
 * exactly the state the configure page had to carry a flag about.
 *
 * SO IT IS RETIRED RATHER THAN DROPPED. The column is not nullable and an
 * installation's rows still hold values, so it survives one release written
 * and never read; the release after this drops it with a `@destructive`
 * migration. What this test holds is the "never read" half — the half that
 * is easy to undo by accident, because the getter is still right there.
 *
 * THE WRITE ON INSERT IS THE ONE EXCEPTION, and it is named here rather
 * than excluded silently: a NOT NULL column has to be given something until
 * the day it is dropped.
 */
final class RetiredCatchmentColumnTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';
    private const string TEMPLATES = __DIR__.'/../../templates';

    /**
     * `default_catchment_metres` — the AREA's fallback ring, a configured
     * setting and not this column — is deliberately not matched: the
     * pattern names the property and its accessors, which is what a read
     * of the retired column actually looks like.
     */

    /** The only place allowed to name it, and why. */
    private const array MAY_NAME_IT = [
        // Declares the column and marks it retired.
        'src/Entity/StationWatch.php',
        // Writes it on insert, because it is NOT NULL until the drop.
        'src/Service/StationWatchService.php',
    ];

    public function testNothingInTheModuleReadsTheRetiredColumn(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            if (\in_array($path, self::MAY_NAME_IT, true)) {
                continue;
            }

            if (preg_match('/\b(getCatchmentMetres|setCatchmentMetres|catchmentMetres)\b/', $code)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These read the RETIRED watch catchment. The ring is the post's — read Station::getCatchmentM() and write through StationService::setCatchment():\n  ".implode("\n  ", $offenders),
        );
    }

    /** AND THE ONE PLACE THAT WRITES IT SAYS WHY, so the drop is findable. */
    public function testTheColumnIsMarkedRetiredWhereItIsDeclared(): void
    {
        $entity = (string) file_get_contents(self::SRC.'/Entity/StationWatch.php');

        self::assertStringContainsString('RETIRED', $entity);
        self::assertStringContainsString('station.catchment_m', $entity, 'It names where the ring actually lives.');
        self::assertStringContainsString('@deprecated', $entity, 'The getter and the setter are marked, so an editor says so too.');
    }

    /**
     * Every PHP and Twig file this module ships, by its path from the root.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $found = [];

        foreach ([self::SRC => 'src', self::TEMPLATES => 'templates'] as $root => $prefix) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $found[$prefix.'/'.ltrim(str_replace($root, '', $file->getPathname()), '/')] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $found;
    }
}
