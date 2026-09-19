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

use Uhifadhi\Roster\Enum\RestRule;

/**
 * A ROTATION AS THE EDITOR HAS IT, before anything is written.
 *
 * NOTHING IS WRITTEN UNTIL SAVE, which is the editor's own promise and the
 * reason this object exists: the ring, the counts and the pool are changed
 * many times in one sitting, and a surface that wrote each click would
 * generate a month per keystroke and leave a half-made rotation behind if
 * somebody closed the tab.
 *
 * IT IS ALSO WHAT THE PREVIEW READS. The month under the editor is what this
 * draft WOULD generate — run through the same pure planner the generator
 * uses, writing nothing — so the preview cannot disagree with the result.
 *
 * UNTRUSTED ON THE WAY IN. It is built from a form field the browser filled,
 * so every field is checked here and a malformed draft is refused with a
 * sentence rather than half-applied.
 */
final readonly class RotationDraft
{
    /**
     * @param list<string>      $cycle             one entry per ring day: a shift key, or {@see Cycle::OFF}
     * @param array<string,int> $slotsPerShift     how many the post asks for, per shift key
     * @param list<string>      $poolUuids         the people, in the order they enter the ring
     * @param list<int>         $standDownWeekdays ISO numbers, monday 1 to sunday 7
     */
    private function __construct(
        public array $cycle,
        public array $slotsPerShift,
        public array $poolUuids,
        public array $standDownWeekdays,
        public RestRule $restRule,
        public int $horizonDays,
        public \DateTimeImmutable $anchoredOn,
    ) {
    }

    /**
     * READ A DRAFT THE EDITOR POSTED.
     *
     * @param array<string, string> $shiftKeys the area's own vocabulary; a ring naming anything else is refused
     *
     * @throws \InvalidArgumentException when the draft is not a rotation anybody could run
     */
    public static function fromSubmitted(mixed $decoded, array $shiftKeys): self
    {
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('The editor sent something that is not a rotation.');
        }

        $cycle = self::strings($decoded['cycle'] ?? null, 'cycle');
        if ([] === $cycle) {
            throw new \InvalidArgumentException('A cycle is a ring of days and a ring of no days repeats nothing. Give it at least one day, which may be "off".');
        }

        foreach ($cycle as $position => $day) {
            if (Cycle::OFF !== $day && !isset($shiftKeys[$day])) {
                throw new \InvalidArgumentException(\sprintf('Day %d of the ring names "%s", which is not one of this area\'s shifts. A ring can only stand a watch the area has a window for.', $position + 1, $day));
            }
        }

        $slots = [];
        foreach (self::intMap($decoded['slots'] ?? null) as $key => $count) {
            if (!isset($shiftKeys[$key])) {
                continue;
            }
            if ($count < 0) {
                throw new \InvalidArgumentException(\sprintf('A post cannot ask for fewer than nobody on the %s watch.', $key));
            }
            $slots[$key] = $count;
        }

        $weekdays = [];
        foreach (self::ints($decoded['standDown'] ?? null) as $weekday) {
            if ($weekday >= 1 && $weekday <= 7) {
                $weekdays[] = $weekday;
            }
        }

        $horizon = \is_int($decoded['horizonDays'] ?? null) ? $decoded['horizonDays'] : 0;
        if ($horizon < 1) {
            throw new \InvalidArgumentException('A horizon of no days generates nothing. Say how far ahead the ring should run.');
        }

        $anchor = \is_string($decoded['anchoredOn'] ?? null)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $decoded['anchoredOn'])
            : false;
        if (false === $anchor) {
            throw new \InvalidArgumentException('Day one of the ring is a date, as YYYY-MM-DD: every offset in the cycle is counted from it.');
        }

        return new self(
            cycle: $cycle,
            slotsPerShift: $slots,
            poolUuids: self::strings($decoded['pool'] ?? null, 'pool'),
            standDownWeekdays: array_values(array_unique($weekdays)),
            restRule: RestRule::tryFrom(\is_string($decoded['restRule'] ?? null) ? $decoded['restRule'] : '') ?? RestRule::None,
            horizonDays: $horizon,
            anchoredOn: $anchor,
        );
    }

    public function cycle(): Cycle
    {
        return Cycle::of($this->cycle);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $field): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw new \InvalidArgumentException(\sprintf('The %s carries an entry that is not a word.', $field));
            }
            $strings[] = $entry;
        }

        return $strings;
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $ints = [];
        foreach ($value as $entry) {
            if (\is_int($entry)) {
                $ints[] = $entry;
            }
        }

        return $ints;
    }

    /**
     * @return array<string, int>
     */
    private static function intMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $entry) {
            if (\is_string($key) && \is_int($entry)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }
}
