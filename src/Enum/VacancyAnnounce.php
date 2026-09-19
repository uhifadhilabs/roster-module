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

namespace Uhifadhi\Roster\Enum;

/**
 * WHEN A HOLE IS ANNOUNCED.
 *
 * The default is AS SOON AS IT IS KNOWN, and the design says why in one
 * example: a ranger who checks in unfit at 05:58 makes tonight's hole real at
 * 05:58, not at 18:00 when nobody turns up. Every other setting on this list
 * trades one cost against another; this one trades a quiet screen against a
 * gate that stands empty, so the quiet screen loses.
 */
enum VacancyAnnounce: string
{
    case AsSoonAsKnown = 'as_soon_as_known';

    case FourHoursBefore = 'four_hours_before';

    case AtTheWatch = 'at_the_watch';

    public function label(): string
    {
        return match ($this) {
            self::AsSoonAsKnown => 'as soon as known',
            self::FourHoursBefore => '4 h before',
            self::AtTheWatch => 'at the watch',
        };
    }

    /** How long before the watch begins the hole becomes visible; null means immediately. */
    public function leadMinutes(): ?int
    {
        return match ($this) {
            self::AsSoonAsKnown => null,
            self::FourHoursBefore => 240,
            self::AtTheWatch => 0,
        };
    }
}
