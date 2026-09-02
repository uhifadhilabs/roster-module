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

namespace UhifadhiLabs\Roster\Module;

use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * Declares the one module this bundle contributes — "Rosters": who is on duty
 * in an area, where and when.
 *
 * No entryRoute() yet. The module has no screens until the roster design is
 * ruled on, so the host renders it through its generic module page; the day the
 * first roster route lands, this returns that route name and the host links
 * straight to it.
 */
final class RosterModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return 'rosters';
    }

    public function name(): string
    {
        return 'Rosters';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'Duty rosters and shift check-ins';
    }

    public function icon(): string
    {
        return 'calendar-clock';
    }
}
