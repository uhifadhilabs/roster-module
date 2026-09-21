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
 * WHERE A STATION'S CYCLE STARTS, AND HOW FAR IT IS FILLED.
 *
 * A RING OF DAYS BECOMES DATES ONLY WITH AN ANCHOR. The sheet draws a day
 * as unfilled when the station's own cycle expects somebody on it and
 * nobody is; without the day the cycle starts from, "expects somebody"
 * has no answer, and a gap could only be noticed after it had happened.
 *
 * BOTH NULLABLE, AND NULL IS THE HONEST ANSWER for every station that
 * exists today: nobody has run a fill at any of them, so nothing is
 * expected of anybody and no cell draws in the alarm ink. The columns
 * fill themselves the first time somebody fills a station from a
 * pattern, which is why there is no backfill — there is nothing to
 * backfill from that would not be a guess.
 */
final class Version20260921000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The day a station\'s cycle starts from, and how far it is filled.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_station_watch ADD pattern_from DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE roster_station_watch ADD filled_through DATE DEFAULT NULL');
    }

    /** Two columns added and nothing dropped, so no `@destructive` marker. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_station_watch DROP COLUMN IF EXISTS pattern_from');
        $this->addSql('ALTER TABLE roster_station_watch DROP COLUMN IF EXISTS filled_through');
    }
}
