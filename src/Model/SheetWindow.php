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
 * THE WEEKS THE SHEET IS SHOWING — one, two or four, and never anything
 * between.
 *
 * RULED 21 sep. Two is the default on a desk and one on a phone; four is
 * the MONTH view and compacts the cell the way the calendar does, so
 * twenty-eight columns and the name column fit without scrolling
 * sideways. There is no three: anything between one and four is a
 * stretched fortnight.
 *
 * IT ALWAYS STARTS ON A MONDAY. A week that began on the day somebody
 * happened to look is not a week anybody plans in — and the window opens
 * on the CURRENT week, so today's column is on screen when the page
 * loads rather than a scroll away.
 */
final readonly class SheetWindow
{
    /** The only three answers, in the order the chip offers them. */
    public const array WEEKS = [1, 2, 4];

    public const int DEFAULT_WEEKS = 2;

    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $through,
        public int $weeks,
        public \DateTimeImmutable $today,
    ) {
    }

    /**
     * @param int|null $weeks anything that is not one of {@see WEEKS} falls back to the default rather than refusing: a hand-typed url is not a reason to show somebody an error page
     */
    public static function of(\DateTimeImmutable $from, ?int $weeks = null, ?\DateTimeImmutable $today = null): self
    {
        $weeks = \in_array($weeks, self::WEEKS, true) ? $weeks : self::DEFAULT_WEEKS;
        $start = $from->setTime(0, 0)->modify('monday this week');

        return new self(
            $start,
            $start->modify(\sprintf('+%d days', 7 * $weeks - 1)),
            $weeks,
            ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0),
        );
    }

    /**
     * EVERY DAY THE SHEET DRAWS, as objects the template iterates rather
     * than arithmetic it repeats per row.
     *
     * @return list<\DateTimeImmutable>
     */
    public function days(): array
    {
        $days = [];
        for ($day = $this->from; $day <= $this->through; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }

    public function dayCount(): int
    {
        return 7 * $this->weeks;
    }

    public function previous(): \DateTimeImmutable
    {
        return $this->from->modify(\sprintf('-%d days', $this->dayCount()));
    }

    public function next(): \DateTimeImmutable
    {
        return $this->from->modify(\sprintf('+%d days', $this->dayCount()));
    }

    public function holds(\DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->from && $day <= $this->through;
    }

    /** "mon 14 – sun 27 sep 2026" — the one `on` chip in the sheet's head. */
    public function label(): string
    {
        $from = strtolower($this->from->format('D j'));
        $through = strtolower($this->through->format('D j M Y'));

        if ($this->from->format('M Y') !== $this->through->format('M Y')) {
            $from = strtolower($this->from->format('D j M'));
        }

        return $from.' – '.$through;
    }

    /** The class the scroller wears: `w1`, `w2` or `w4`. */
    public function widthClass(): string
    {
        return 'w'.$this->weeks;
    }
}
