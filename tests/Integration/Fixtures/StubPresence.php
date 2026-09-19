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

namespace Uhifadhi\Roster\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;

/**
 * THE AREA'S DERIVATION, STUBBED — the one place in this suite where a
 * collaborator is not the real thing, and it is deliberate.
 *
 * WHAT IS UNDER TEST IS THIS MODULE'S READING of whatever the contract
 * returns, and the case that matters most — A DAY HOLDING MORE THAN ONE
 * WATCH — cannot be set up any other way: the check-ins, the pings and the
 * catchment that produce a `PersonDay` are the AREA's rows, and writing
 * them here would be this module inventing the very thing it is ruled never
 * to compute.
 *
 * So the stub hands over `PersonDay` objects directly. It impersonates
 * nothing and asserts nothing; it is a fixture standing where the area
 * stands, and the module's join is what is being measured.
 */
final readonly class StubPresence implements PresenceProviderInterface
{
    /**
     * @param list<PersonDay> $days everything the area would report for the day under test
     */
    public function __construct(
        private array $days = [],
    ) {
    }

    public function dayIn(string $areaUuid, string $localDate): array
    {
        return array_values(array_filter(
            $this->days,
            static fn (PersonDay $day): bool => $day->localDate === $localDate,
        ));
    }

    public function dayFor(string $areaUuid, string $personUuid, string $localDate): ?PersonDay
    {
        foreach ($this->dayIn($areaUuid, $localDate) as $day) {
            if ($day->personUuid === $personUuid) {
                return $day;
            }
        }

        return null;
    }
}
