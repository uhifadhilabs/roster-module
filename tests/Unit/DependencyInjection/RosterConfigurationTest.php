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

namespace Uhifadhi\Roster\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;

final class RosterConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('roster');
        RosterConfiguration::define($builder->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($builder->buildTree(), ['roster' => $config]);

        return $processed;
    }

    public function testDefaultsFileTheModuleUnderFluxWithoutDevTools(): void
    {
        $config = $this->process([]);

        self::assertSame('operations', $config['module_category']);
        self::assertFalse($config['dev_tools']);
    }

    public function testADeploymentFilesTheModuleWhereItWants(): void
    {
        self::assertSame('pressure', $this->process(['module_category' => 'pressure'])['module_category']);
    }

    public function testAnEmptyCategoryIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['module_category' => '']);
    }

    public function testTheTreeIsClosedToUnknownKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        // Until the design rules on the domain model there is no shift or
        // station vocabulary to configure; an invented key must fail loudly
        // rather than be ignored.
        $this->process(['shift_patterns' => ['day' => ['label' => 'Day shift']]]);
    }
}
