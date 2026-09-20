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

use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * THE HOUSE'S OWN CONFORMANCE RULES, run against this module.
 *
 * THE SHELL SHIPS THESE, so every module is measured by one set and a rule
 * the house learns reaches all of them at once. This module had grown its
 * own versions of two of them; the house's are stricter and are not mine to
 * keep in step.
 */
final class RosterVocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function alias(): string
    {
        return 'roster';
    }

    protected static function ownStylesheets(): array
    {
        return ['roster.css'];
    }

    /**
     * AND THE WIDGET GRID'S SHEET, which this module's bases link because
     * three of its surfaces are COMPOSED — without it nothing declares a
     * span and every widget draws one column wide. It is the shell's and
     * shipped separately, because only a page composing a surface needs
     * it, so a module that links it has to say so here.
     */
    protected static function linkedStylesheets(): array
    {
        return [
            ...parent::linkedStylesheets(),
            \dirname((new \ReflectionClass(\Uhifadhi\Bundle\ShellBundle\ShellBundle::class))->getFileName() ?: '').'/public/widget.css',
            /*
             * AND THE AREA'S OWN SHEET, because one of this module's
             * templates is not drawn on one of this module's pages: the
             * organisation dashboard's watches cell is rendered by the
             * CORE, on a page that links the area vocabulary before any
             * module's. The contributor tag `.ao-by` and the honest-absent
             * paragraph are that vocabulary, and a cell that restated them
             * would be the drift this whole test exists to stop.
             */
            \dirname((new \ReflectionClass(\Uhifadhi\Bundle\AreaBundle\AreaBundle::class))->getFileName() ?: '').'/public/area.css',
        ];
    }
}
