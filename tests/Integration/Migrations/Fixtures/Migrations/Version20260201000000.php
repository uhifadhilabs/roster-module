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

namespace Uhifadhi\Roster\Tests\Integration\Migrations\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BREAKS RULE ONE, deliberately: a required column on a table an earlier version
 * created, with neither a DEFAULT nor an UPDATE beside it. On the first
 * installation that has duties this fails halfway.
 *
 * Never shipped — this directory is not registered with the migrations bundle.
 */
final class Version20260201000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A required column with nothing to fill it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_duty ADD sector VARCHAR(64) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_duty DROP sector');
    }
}
