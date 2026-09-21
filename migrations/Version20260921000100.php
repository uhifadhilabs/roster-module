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
 * THE PLANNING SHEET'S SCHEMA — the four filling rules, and a hand mark
 * that is about one ranger's day rather than a whole station's.
 *
 * TWO SHAPES OF ANSWER. Two of the four filling rules are picked and not
 * measured, so a rule row's number and unit become nullable and a `choice`
 * column joins them; exactly one of the two pairs is filled on any row,
 * and which one is the kind's to say. Existing rows are all measured and
 * are untouched.
 *
 * A HAND MARK IS A PERSON'S DAY. The sheet is people down, so "edited by
 * hand" is a mark on one ranger's cell — the design draws it on two of
 * five rangers at one station on one day, which a station-wide mark can
 * never say. The old station-wide mark keeps its exact meaning (person is
 * null) and the generator keeps reading exactly those rows, so nothing
 * that reads this table today changes behaviour.
 *
 * AND WHETHER THE HAND LEFT THE DAY OFF OR LEFT IT OPEN. Both are a day
 * with no duty on it and they are not the same fact: one is a ranger
 * deliberately stood down, the other a seat the station still needs
 * somebody on. `left_off` is that difference, and it defaults to false so
 * every existing mark keeps meaning what it meant.
 */
final class Version20260921000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The four filling rules, and a hand mark about one ranger\'s day.';
    }

    public function up(Schema $schema): void
    {
        // ---- a rule's answer is measured OR chosen ------------------------
        foreach (['roster_shift_rule', 'roster_station_rule_exception'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER COLUMN value DROP NOT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER COLUMN unit DROP NOT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD choice VARCHAR(32) DEFAULT NULL', $table));
        }

        // ---- a hand mark is a person's day --------------------------------
        $this->addSql('ALTER TABLE roster_edited_day ADD person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roster_edited_day ADD CONSTRAINT fk_roster_edited_day_person FOREIGN KEY (person_id) REFERENCES team_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_roster_edited_day_person ON roster_edited_day (person_id)');

        $this->addSql('ALTER TABLE roster_edited_day ADD left_off BOOLEAN DEFAULT NULL');
        $this->addSql('UPDATE roster_edited_day SET left_off = FALSE WHERE left_off IS NULL');
        $this->addSql('ALTER TABLE roster_edited_day ALTER COLUMN left_off SET NOT NULL');

        /*
         * NULLS ARE DISTINCT IN A POSTGRES UNIQUE INDEX, so one index over
         * (station, day, person) would let a station-wide mark be written
         * twice. Two partial indexes say what is actually meant: one
         * station-wide mark per day, and one mark per ranger per day.
         */
        $this->addSql('DROP INDEX IF EXISTS uniq_roster_edited_day_station_day');
        $this->addSql('CREATE UNIQUE INDEX uniq_roster_edited_day_station_day ON roster_edited_day (station_id, on_day) WHERE person_id IS NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_roster_edited_day_station_day_person ON roster_edited_day (station_id, on_day, person_id) WHERE person_id IS NOT NULL');
    }

    /**
     * NOTHING IN `up()` DROPS ANYTHING, so there is no `@destructive`
     * marker: two columns loosen, three are added, and the one index that
     * is replaced is replaced by a pair that says the same thing about the
     * rows that exist.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_roster_edited_day_station_day_person');
        $this->addSql('DROP INDEX IF EXISTS uniq_roster_edited_day_station_day');
        $this->addSql('CREATE UNIQUE INDEX uniq_roster_edited_day_station_day ON roster_edited_day (station_id, on_day)');
        $this->addSql('ALTER TABLE roster_edited_day DROP CONSTRAINT IF EXISTS fk_roster_edited_day_person');
        $this->addSql('DROP INDEX IF EXISTS idx_roster_edited_day_person');
        $this->addSql('ALTER TABLE roster_edited_day DROP COLUMN IF EXISTS person_id');
        $this->addSql('ALTER TABLE roster_edited_day DROP COLUMN IF EXISTS left_off');

        foreach (['roster_shift_rule', 'roster_station_rule_exception'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS choice', $table));
        }
    }
}
