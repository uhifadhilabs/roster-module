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

use Uhifadhi\Roster\Model\RuleValue;

/**
 * THE FIVE RULES AN AREA SETS, AND ANY STATION MAY OVERRULE.
 *
 * RULED 20 sep. Owner: "rules configurable like exceptions" — any rule,
 * not just hours. So the five are one list with one shape, an area default
 * each and a per-station exception under any of them, and nothing in the
 * product assumes one way of working.
 *
 * RAISE UNFILLED IS ABOUT THE DASHBOARD AND NOT ABOUT THE SHEET (ruled 21
 * sep, and the reason the checkbox beside it is gone). An unfilled shift
 * is on the sheet the moment it exists — always, with no setting. This
 * rule says only when it is RAISED as needing a decision, which is why the
 * row reads "raise unfilled 4 hours before it starts · as needing a
 * decision" and there is nothing to tick.
 */
enum RuleKind: string
{
    case LateAfter = 'late_after';
    case OfflineAfter = 'offline_after';
    case PingEvery = 'ping_every';
    case CheckInWithin = 'check_in_within';
    case RaiseUnfilled = 'raise_unfilled';

    /** The uppercase mono label at the head of the row. */
    public function label(): string
    {
        return match ($this) {
            self::LateAfter => 'Late after',
            self::OfflineAfter => 'Offline after',
            self::PingEvery => 'Ping every',
            self::CheckInWithin => 'Check-in within',
            self::RaiseUnfilled => 'Raise unfilled',
        };
    }

    /**
     * THE FRAGMENT AFTER THE CONTROL — what the rule is about, in three or
     * four words.
     *
     * NOT A SENTENCE (cut 21 sep). Each rule used to carry a second line
     * saying what happens if you get the value wrong; five of them was
     * about 330 words standing between five controls, and it is what made
     * the card a wall. The reasoning lives in the markup's comments for
     * whoever implements it; the screen states facts.
     */
    public function fragment(): string
    {
        return match ($this) {
            self::LateAfter => 'without a ping',
            self::OfflineAfter => 'the map stops claiming to know',
            self::PingEvery => 'per handset',
            self::CheckInWithin => 'of the station',
            self::RaiseUnfilled => 'before it starts · as needing a decision',
        };
    }

    public function measuresTime(): bool
    {
        return self::CheckInWithin !== $this;
    }

    /**
     * The units this rule may be stated in, in the order the select offers
     * them.
     *
     * @return list<RuleUnit>
     */
    public function units(): array
    {
        return $this->measuresTime()
            ? [RuleUnit::Minutes, RuleUnit::Hours, RuleUnit::Days]
            : [RuleUnit::Kilometres, RuleUnit::Metres];
    }

    /**
     * WHAT THIS AREA RUNS AT BEFORE ANYBODY TOUCHES IT. Not a blessed
     * option — a starting point, which every one of the five is free of
     * the moment somebody types over it.
     */
    public function standard(): RuleValue
    {
        return match ($this) {
            self::LateAfter => new RuleValue(2.0, RuleUnit::Hours),
            self::OfflineAfter => new RuleValue(1.0, RuleUnit::Days),
            self::PingEvery => new RuleValue(30.0, RuleUnit::Minutes),
            self::CheckInWithin => new RuleValue(1.5, RuleUnit::Kilometres),
            self::RaiseUnfilled => new RuleValue(4.0, RuleUnit::Hours),
        };
    }

    /**
     * A VALUE OF THIS KIND, refused where the unit cannot measure it.
     *
     * @throws \InvalidArgumentException when the unit measures the wrong thing
     */
    public function valueOf(float $value, RuleUnit $unit): RuleValue
    {
        if ($unit->measuresTime() !== $this->measuresTime()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is measured in %s, and %s does not measure that.', $this->label(), $this->measuresTime() ? 'time' : 'ground', $unit->value));
        }

        return new RuleValue($value, $unit);
    }
}
