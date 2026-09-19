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

/**
 * THE RING OF DAYS A ROTATION REPEATS FOR EVER — "2 day, 2 night, 1 off".
 *
 * A ring and not a schedule: it has no dates in it at all. Which day of the
 * ring a given calendar date is depends on the rotation's anchor, and which
 * position a given PERSON is at depends on where they stand in the pool —
 * each person enters the ring one day later than the last, which is how a
 * five-day ring of two days, two nights and one off staffs a post with
 * exactly two on days and two on nights out of a pool of five, for ever,
 * without anybody writing a rota.
 *
 * NOT PERSISTED AS A TABLE. A ring is meaningless apart from the rotation it
 * belongs to and is never queried across rotations, so it is stored as one
 * JSON column and thought in as this value object. The test is the usual one:
 * nothing asks the database a question about a single ring position.
 */
final readonly class Cycle
{
    /**
     * THE WORD FOR A STOOD-DOWN DAY, and it is reserved: no shift may be
     * called `off`, because a ring stores shift keys and a shift named `off`
     * would make a stored ring ambiguous about whether a person is on the
     * off-watch or standing down.
     */
    public const string OFF = 'off';

    /**
     * @param list<string> $positions one entry per day of the ring: a shift key, or {@see OFF}
     */
    private function __construct(
        public array $positions,
    ) {
    }

    /**
     * @param list<string> $positions
     *
     * @throws \InvalidArgumentException when the ring is empty or holds a blank position
     */
    public static function of(array $positions): self
    {
        if ([] === $positions) {
            throw new \InvalidArgumentException('A cycle is a ring of days and a ring of no days repeats nothing. Give it at least one day, which may be "off".');
        }

        foreach ($positions as $index => $position) {
            if ('' === trim($position)) {
                throw new \InvalidArgumentException(\sprintf('Day %d of the cycle names no shift. A day is a shift key or "off"; there is no third answer, and a blank one would generate a watch nobody could read.', $index + 1));
            }
        }

        return new self($positions);
    }

    /**
     * READ A STORED RING BACK, tolerantly enough to survive a hand-edited
     * column and strictly enough that a malformed one fails here rather than
     * three screens later.
     *
     * @throws \InvalidArgumentException when the stored value is not a list of non-empty strings
     */
    public static function fromStored(mixed $stored): self
    {
        if (!\is_array($stored)) {
            throw new \InvalidArgumentException('A stored cycle is a JSON list of day entries.');
        }

        $positions = [];
        foreach ($stored as $position) {
            if (!\is_string($position)) {
                throw new \InvalidArgumentException('Every day of a stored cycle is a shift key or "off".');
            }
            $positions[] = $position;
        }

        return self::of($positions);
    }

    /** How many days the ring is long. Never zero. */
    public function length(): int
    {
        return \count($this->positions);
    }

    /**
     * WHAT THE RING SAYS AT A GIVEN OFFSET, wrapping for ever in both
     * directions — a negative offset is a date before the anchor, which is a
     * perfectly ordinary question to ask of a ring that has always repeated.
     *
     * PHP's % keeps the sign of the dividend, so -1 % 5 is -1 and not 4. The
     * extra add-and-wrap is what makes this a ring rather than a list with a
     * cliff at the anchor.
     */
    public function at(int $offset): string
    {
        $length = $this->length();

        return $this->positions[(($offset % $length) + $length) % $length];
    }

    /** Whether the ring stands a person down at this offset. */
    public function isOffAt(int $offset): bool
    {
        return self::OFF === $this->at($offset);
    }

    /**
     * The shift keys this ring actually stands, in ring order and without
     * repeats — what a rotation editor offers a per-day count for.
     *
     * @return list<string>
     */
    public function shiftKeys(): array
    {
        $keys = [];
        foreach ($this->positions as $position) {
            if (self::OFF !== $position && !\in_array($position, $keys, true)) {
                $keys[] = $position;
            }
        }

        return $keys;
    }

    /**
     * @return list<string> the shape the entity stores
     */
    public function toStored(): array
    {
        return $this->positions;
    }
}
