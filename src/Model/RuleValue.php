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

namespace Uhifadhi\Roster\Model;

use Uhifadhi\Roster\Enum\RuleUnit;

/**
 * ONE RULE'S ANSWER — the number somebody typed and the unit they picked.
 *
 * BOTH HALVES ARE STORED, and that is the whole point of the object. "1
 * day" and "1440 minutes" are the same threshold and not the same answer:
 * a value kept canonically and rendered in whatever unit looked tidiest
 * would turn somebody's day into minutes the moment they saved it, and
 * they would never type a day again.
 *
 * THE CANONICAL NUMBER IS DERIVED WHEN SOMETHING MEASURES AGAINST IT.
 * Every reading in this module is already written in minutes and metres,
 * so this converts on the way out rather than asking forty call sites to
 * learn about units.
 */
final readonly class RuleValue
{
    public function __construct(
        public float $value,
        public RuleUnit $unit,
    ) {
        if ($value <= 0.0) {
            throw new \InvalidArgumentException('A threshold is a positive number; zero and below name a rule nothing can ever be on either side of.');
        }
    }

    /** The threshold in minutes — what every duration reading asks for. */
    public function toMinutes(): int
    {
        return (int) round($this->value * $this->unit->minutes());
    }

    /** The threshold in metres — what a catchment is measured in. */
    public function toMetres(): int
    {
        return (int) round($this->value * $this->unit->metres());
    }

    /** "2 hours", "1 day", "1.5 km" — the pair as somebody reads it back. */
    public function label(): string
    {
        return $this->number().' '.$this->unit->labelFor($this->value);
    }

    /** The number without a trailing nothing: 30, not 30.0; 1.5 stays 1.5. */
    public function number(): string
    {
        return rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.');
    }
}
