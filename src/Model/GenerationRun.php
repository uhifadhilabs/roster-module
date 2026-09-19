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
 * WHAT ONE RUN OF THE GENERATOR DID — and, more usefully, what it declined to
 * do and why.
 *
 * The counts are not decoration. A run that wrote nothing has three possible
 * explanations a person needs told apart: every day in the window was edited
 * by hand, everybody in the pool is away, or the ring stands nobody. A single
 * "0 created" answers none of them.
 */
final readonly class GenerationRun
{
    /**
     * @param list<string> $protectedDays the Y-m-d days left alone because somebody had edited them
     */
    public function __construct(
        public int $created,
        public int $replaced,
        public array $protectedDays,
        public int $skippedAbsent,
        public int $skippedAlreadyThere,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $through,
    ) {
    }

    public static function nothing(\DateTimeImmutable $from, \DateTimeImmutable $through): self
    {
        return new self(0, 0, [], 0, 0, $from, $through);
    }

    public function protectedDayCount(): int
    {
        return \count($this->protectedDays);
    }
}
