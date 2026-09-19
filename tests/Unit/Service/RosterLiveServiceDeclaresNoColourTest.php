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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * A MODULE DECLARES NO COLOUR, AND READS NOBODY ELSE'S.
 *
 * TWO HALVES OF ONE RULE, and this module broke the second one in a way
 * nothing else here could catch. The first half is well known: no hex in a
 * module, not even mirrored with a comment, and not even at a seam that
 * JavaScript reads — a layer publishes a token NAME and whoever owns the
 * plate resolves it. The second half is the one that bites: a module must
 * not read another module's palette either.
 *
 * WHY READING IS AS BAD AS DECLARING. A zone's colour belongs to the area.
 * A module that reaches for it has to be edited every time the area changes
 * how it stores one — and, worse, it can disagree with the very page it
 * copied the mapping from and nothing will say so. The correct arrangement
 * is to pass the area's own rows through untouched and let the area hue
 * them, which is also the only way the same zone is the same colour on
 * every plate in the product.
 *
 * SO THE TEST IS A TEXT SCAN, deliberately. There is no type it could
 * assert against — `$row->hue` is a property read that compiles and runs
 * right up until the field is renamed, and a kernel test would only fail
 * once the area got round to renaming it. This fails where somebody would
 * be editing, on the day they write it.
 */
final class RosterLiveServiceDeclaresNoColourTest extends TestCase
{
    private const string SERVICE = __DIR__.'/../../../src/Service/RosterLiveService.php';

    /**
     * The fields an area model carries a colour in, in every spelling the
     * core has used or is moving to. A read of any of them is the bug.
     */
    private const array COLOUR_FIELDS = ['hue', 'cat', 'colour', 'color', 'swatch', 'palette'];

    private static function source(): string
    {
        $source = file_get_contents(self::SERVICE);
        self::assertIsString($source, 'The service must be readable.');

        return $source;
    }

    /** No literal colour of its own, in any notation. */
    public function testItDeclaresNoColour(): void
    {
        $source = self::source();

        self::assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{3,8}\b/', $source, 'A hex is a colour this module declared.');
        self::assertDoesNotMatchRegularExpression('/\brgba?\s*\(/i', $source);
        self::assertDoesNotMatchRegularExpression('/\bhsla?\s*\(/i', $source);
    }

    /**
     * AND IT READS NO COLOUR-BEARING FIELD OFF AN AREA MODEL. A property
     * read or an array key — `$zone->hue`, `$row->cat`, `'hue' => …` — is
     * this module holding an opinion about somebody else's palette.
     */
    public function testItReadsNoColourFromAnAreaModel(): void
    {
        $source = self::source();

        // Comments say what the rule is; only code is scanned for breaking it.
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        foreach (self::COLOUR_FIELDS as $field) {
            self::assertDoesNotMatchRegularExpression(
                '/->'.$field.'\b/i',
                $code,
                \sprintf('This module reads ->%s off somebody else\'s model. A zone\'s colour is the area\'s.', $field),
            );

            self::assertDoesNotMatchRegularExpression(
                '/[\'"]'.$field.'[\'"]\s*=>/i',
                $code,
                \sprintf('This module passes a "%s" along. It should pass the area\'s rows through and let the area hue them.', $field),
            );
        }
    }

    /**
     * WHAT IT DOES INSTEAD: publishes the plate palette's token names and
     * lets whoever owns the plate resolve them.
     */
    public function testItPublishesTokenNamesForItsOwnLayers(): void
    {
        $source = self::source();

        foreach (['var(--plate-ok)', 'var(--plate-warn)', 'var(--plate-fail)', 'var(--plate-dim)', 'var(--plate-acc)'] as $token) {
            self::assertStringContainsString($token, $source);
        }
    }
}
