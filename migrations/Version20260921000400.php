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
 * A GAP BELONGS TO THE STATION, AND SO DOES THE RULE ABOUT IT.
 *
 * RULED 21 sep: a ranger is on a shift or off, and what can be SHORT is
 * a station on a day against the number it names. The rule that used to
 * be "raise unfilled" is now "raise short cover" — the same question,
 * asked of the thing that can actually answer it — so the stored value
 * on every rule row and every station exception is renamed with it.
 *
 * DATA ONLY, and idempotent: an installation that has never written a
 * rule row has nothing to rename, and running it twice renames nothing
 * the second time.
 */
final class Version20260921000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raise unfilled becomes raise short cover: the gap is the station\'s.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE roster_shift_rule SET kind = 'raise_short_cover' WHERE kind = 'raise_unfilled'");
        $this->addSql("UPDATE roster_station_rule_exception SET kind = 'raise_short_cover' WHERE kind = 'raise_unfilled'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE roster_shift_rule SET kind = 'raise_unfilled' WHERE kind = 'raise_short_cover'");
        $this->addSql("UPDATE roster_station_rule_exception SET kind = 'raise_unfilled' WHERE kind = 'raise_short_cover'");
    }
}
