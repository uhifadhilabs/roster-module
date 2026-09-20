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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE CATCHMENT IS WRITTEN, AND VERIFICATION READS IT.
 *
 * THIS TEST USED TO ASSERT THE OPPOSITE. A post's catchment is the ring a
 * claim is measured against, and for a while nothing in the product wrote
 * that column: the roster's configure page edited a number, the area had no
 * verb for it, and every at-post claim in every installation came back
 * "unverified — the post has no ring" whatever anybody set. A guard here
 * pinned the flag on that page to the absence of the verb, so that the day
 * the core grew one it would go red and say so. It did, and this is what it
 * became.
 *
 * WHAT IT HOLDS NOW IS THE BEHAVIOUR AND NOT THE PLUMBING: a fix inside the
 * ring reads verified, the same claim from outside it reads unverified, and
 * the ring itself is set through the area's own service. The roster states
 * the distance and the AREA does the measuring — which is the whole
 * division, and the reason this module may never compute presence itself.
 */
final class CatchmentVerificationTest extends IntegrationTestCase
{
    /** Far enough outside any sane ring that no tolerance swallows it. */
    private const float A_LONG_WAY = 0.05;

    public function testTheAreaExposesTheVerbThatWritesAPostsCatchment(): void
    {
        $service = new \ReflectionClass(StationService::class);

        self::assertTrue(
            $service->hasMethod('setCatchment'),
            'The verb this module writes the configure page\'s distance through has gone.',
        );
        self::assertTrue($service->getMethod('setCatchment')->isPublic());
    }

    /** INSIDE THE RING, the claim is borne out. */
    public function testAClaimFromInsideTheRingReadsVerified(): void
    {
        self::assertSame(DayState::AtPostVerified, $this->stateFor('inside', 0.0));
    }

    /** OUTSIDE IT, the same claim is a claim and the area says so. */
    public function testTheSameClaimFromOutsideTheRingReadsUnverified(): void
    {
        self::assertSame(DayState::AtPostUnverified, $this->stateFor('outside', self::A_LONG_WAY));
    }

    /**
     * ONE CLAIM, MADE AT THE POST AND MEASURED AGAINST ITS RING.
     *
     * The fix is offset from the post by `$offset` degrees, so the two
     * tests above differ in exactly one thing: where the handset was.
     */
    private function stateFor(string $ref, float $offset): DayState
    {
        $area = $this->anArea();
        $station = $this->aStation($area, 'north gate post');
        $person = $this->aPerson(\sprintf('%s@example.test', $ref));
        $this->em->flush();

        $stations = $this->service(StationService::class);
        self::assertInstanceOf(StationService::class, $stations);
        $stations->setCatchment($station, 250);

        $statuses = $this->service(CheckInStatusService::class);
        self::assertInstanceOf(CheckInStatusService::class, $statuses);

        $atPost = null;
        foreach ($statuses->offeredBy($area) as $status) {
            if (CheckInStatusKind::AtPost === $status->getKind()) {
                $atPost = $status;
            }
        }
        self::assertNotNull($atPost, 'The area offers an "at post" status out of the box.');

        $door = $this->service(CheckInService::class);
        self::assertInstanceOf(CheckInService::class, $door);

        $today = new \DateTimeImmutable('today');
        $door->claim($area, $person, [
            'clientRef' => 'catchment-'.$ref,
            'localDate' => $today->format('Y-m-d'),
            'status' => $atPost->getKey(),
            'occurredAt' => $today->setTime(8, 0)->format(\DATE_ATOM),
            'deviceId' => 'catchment-handset',
            'appVersion' => '1.4.0',
            'stationUuid' => (string) $station->getUuidString(),
            'lat' => -5.7 + $offset,
            'lon' => 12.3,
            'accuracyM' => 8.0,
            'positionAt' => $today->setTime(8, 0)->format(\DATE_ATOM),
        ]);
        $this->em->flush();

        $presence = self::getContainer()->get('test_public.'.PresenceProviderInterface::class);
        self::assertInstanceOf(PresenceProviderInterface::class, $presence);

        self::assertInstanceOf(User::class, $person);
        $day = $presence->dayFor(
            (string) $area->getUuidString(),
            (string) $person->getUuidString(),
            $today->format('Y-m-d'),
        );

        self::assertNotNull($day, 'The area read the claim back.');

        return $day->state;
    }
}
