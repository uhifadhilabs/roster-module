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
 * THE UNITS A RULE MAY BE STATED IN — the second half of "a number the
 * user types and a unit they pick".
 *
 * RULED 20 sep: no menu of blessed VALUES. The units are not that menu;
 * they are what the number means, and a threshold with no unit is not a
 * threshold. Three measure time and two measure ground, and no rule is
 * ever offered one that cannot measure it.
 */
enum RuleUnit: string
{
    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';
    /**
     * WEEKS AND MONTHS EXIST FOR ONE RULE — "fill ahead", which nobody
     * states in hours. A month is thirty days here and says so: an area
     * that wants the calendar month picks days and types the number.
     */
    case Weeks = 'weeks';
    case Months = 'months';

    case Kilometres = 'km';
    case Metres = 'm';

    /** What the select prints — the design's own words. */
    public function label(): string
    {
        return $this->value;
    }

    /** The same word for one of something: "1 day", not "1 days". */
    public function labelFor(float $value): string
    {
        return 1.0 === $value ? rtrim($this->value, 's') : $this->value;
    }

    public function measuresTime(): bool
    {
        return \in_array($this, [self::Minutes, self::Hours, self::Days, self::Weeks, self::Months], true);
    }

    /** How many minutes one of this unit is; zero where it measures ground. */
    public function minutes(): int
    {
        return match ($this) {
            self::Minutes => 1,
            self::Hours => 60,
            self::Days => 1440,
            self::Weeks => 10080,
            self::Months => 43200,
            default => 0,
        };
    }

    /**
     * How many days one of this unit is, rounded up where the unit is
     * shorter than a day — "fill ahead 90 minutes" is a day of filling,
     * never none.
     */
    public function days(): int
    {
        $minutes = $this->minutes();

        return 0 === $minutes ? 0 : (int) ceil($minutes / 1440);
    }

    /** How many metres one of this unit is; zero where it measures time. */
    public function metres(): int
    {
        return match ($this) {
            self::Kilometres => 1000,
            self::Metres => 1,
            default => 0,
        };
    }
}
