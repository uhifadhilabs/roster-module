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

namespace Uhifadhi\Roster\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE SEEDED SHIFTS TAKE THE SLOTS THE DESIGN GIVES THEM.
 *
 * Version20260921000000 gave every existing shift a slot from its POSITION
 * in its area's list, because position was the only stable thing that
 * differed between two shifts at the time. For an area seeded from the
 * product's own four windows that puts night on slot 2 — a yellow — where
 * the design has it on 5, the deep violet, and a night watch that reads as
 * a day watch's neighbour is the one confusion the colour exists to prevent.
 *
 * SO THE FOUR SEEDED KEYS TAKE THEIR OWN SLOTS: day 1, office 3, night 5,
 * radio 8 — the same four RosterConfiguration::DEFAULT_SHIFTS now seeds a
 * new area with. Position keeps the others.
 *
 * AND IT TOUCHES NOTHING SOMEBODY CHOSE. A shift is moved only where it is
 * still wearing the slot its position handed it and the slot it is moving
 * to is free in that area, so a park that has already picked its colours
 * comes through this migration unchanged.
 *
 * Data only, and running it twice moves nothing the second time.
 */
final class Version20260921000500 extends AbstractMigration
{
    /** The seeded key, the slot its position handed it, and the slot the design gives it. */
    private const array MOVES = [
        'night' => [2, 5],
        'radio' => [4, 8],
    ];

    public function getDescription(): string
    {
        return 'Night is the deep violet and radio night its own hue, as the design draws them.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::MOVES as $key => [$from, $to]) {
            $this->move($key, $from, $to);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::MOVES as $key => [$from, $to]) {
            $this->move($key, $to, $from);
        }
    }

    private function move(string $key, int $from, int $to): void
    {
        $this->addSql(
            <<<'SQL'
                UPDATE roster_shift s SET colour = :to
                WHERE s.shift_key = :key
                  AND s.colour = :from
                  AND NOT EXISTS (
                      SELECT 1 FROM roster_shift o
                      WHERE o.area_id = s.area_id AND o.id <> s.id AND o.colour = :to
                  )
                SQL,
            ['key' => $key, 'from' => $from, 'to' => $to],
        );
    }
}
