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
use Uhifadhi\Bundle\AreaBundle\Service\StationService;

/**
 * THE FLAG ON THE CONFIGURE PAGE IS PINNED TO THE GAP IT DESCRIBES.
 *
 * A post's watch carries a catchment and the configure page edits it, but
 * VERIFICATION DOES NOT READ IT: the area measures a ping against the POST's
 * own catchment column, and nothing in the product writes that column —
 * there is no service verb for it, no form field, and the area's own
 * `describe()` enumerates a post's facts without it. So every at-post claim
 * in every installation reads "unverified — the post has no ring", whatever
 * the roster's field is set to.
 *
 * THE ROSTER MAY NOT CLOSE THE GAP FROM HERE. Writing that column would be
 * this module computing presence, which it is ruled never to do, and it
 * would go on agreeing with itself long after the real derivation moved. The
 * page states the fact instead.
 *
 * SO THIS TEST HOLDS BOTH ENDS. It asserts the verb is still missing AND
 * that the page still says so — which means the day the core grows the verb
 * this goes red, and whoever adds it is told, here, that a note somewhere
 * else has become a lie and a field has become writable.
 */
final class CatchmentFlagTest extends TestCase
{
    private const string WATCHES = __DIR__.'/../../../templates/configure/watches.html.twig';

    /**
     * The words that would have to appear on a verb that wrote it. A method
     * named for the catchment is the only shape such a verb can take, and a
     * new parameter on an existing one is the other.
     */
    public function testTheAreaStillExposesNoVerbThatWritesAPostsCatchment(): void
    {
        $service = new \ReflectionClass(StationService::class);

        foreach ($service->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringNotContainsStringIgnoringCase(
                'catchment',
                $method->getName(),
                'The core has grown a verb for a post\'s catchment: wire the roster\'s field through it and take the flag off the configure page.',
            );

            foreach ($method->getParameters() as $parameter) {
                self::assertStringNotContainsStringIgnoringCase(
                    'catchment',
                    $parameter->getName(),
                    \sprintf('%s() now takes a catchment: wire the roster\'s field through it and take the flag off the configure page.', $method->getName()),
                );
            }
        }
    }

    /** And while it is missing, the page that edits the field says so. */
    public function testTheWatchesSectionFlagsThatVerificationDoesNotReadIt(): void
    {
        $template = (string) file_get_contents(self::WATCHES);

        self::assertStringContainsString('rfrag flagged', $template, 'The gap is marked, not dropped in with the ordinary commentary.');
        self::assertStringContainsString('verification does not read it yet', $template);
    }
}
