<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Rosters Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Tests\Integration\Fixtures;

use Uhifadhi\ModuleContracts\ModuleProviderInterface;

/**
 * The HOST's module catalogue, played by a fixture: it receives every service
 * tagged "uhifadhi.module" exactly as the host's own registry does, so a test
 * can see what this bundle actually contributed to the seam.
 */
final readonly class CollectedModules
{
    /**
     * @param iterable<ModuleProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, ModuleProviderInterface> keyed by slug
     */
    public function bySlug(): array
    {
        $bySlug = [];
        foreach ($this->providers as $provider) {
            $bySlug[$provider->slug()] = $provider;
        }

        return $bySlug;
    }
}
