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

namespace Uhifadhi\Roster\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * AN EMPTY DATABASE, AND ONE IMPLEMENTATION OF IT.
 *
 * EVERY TABLE IN THE SCHEMA, GONE — not just the ones the mapping happens
 * to describe. `SchemaTool::dropSchema()` emits a DROP per MAPPED table and
 * SWALLOWS a statement that fails, so anything the mapping does not know
 * about survives; if it holds a foreign key, the drop it blocks is
 * swallowed too, and the next `createSchema()` collides with a table that
 * was supposed to be gone.
 *
 * A table an EXTENSION owns is left alone — `pg_depend.deptype = 'e'` marks
 * one, PostGIS's `spatial_ref_sys` is one, and dropping it needs rights an
 * application user does not have.
 *
 * WHY IT IS A TRAIT AND NOT A BASE CLASS METHOD. The integration suite
 * extends `KernelTestCase` and the functional suite extends `WebTestCase`,
 * so there is no common parent to put it on; the functional tests
 * therefore grew their own `dropSchema()` + `createSchema()` pair, thirteen
 * times, with the defect this routine exists to avoid.
 *
 * THAT DEFECT IS ORDER-DEPENDENT, which is why it was invisible. In the
 * order PHPUnit happens to run the suite in, the functional tests follow
 * one another and each one's leftovers are the previous one's tables, which
 * the mapping does know about. Reorder the suite — `--order-by=random` — and
 * a functional test lands after an integration test whose kernel mapped a
 * different set, the swallowed DROP leaves a table behind, and the next
 * CREATE fails with "relation already exists". Found on two of four seeds.
 */
trait FreshDatabase
{
    /**
     * Drop whatever is there, then build the schema this kernel maps.
     */
    protected static function freshDatabase(EntityManagerInterface $em): void
    {
        self::dropEveryTable($em);

        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private static function dropEveryTable(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();

        $tables = $connection->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND NOT EXISTS (
                  SELECT 1 FROM pg_depend d
                  WHERE d.objid = c.oid AND d.deptype = 'e'
              )
            SQL);

        if ([] === $tables) {
            return;
        }

        // One statement, not one per table: this runs before every test and
        // Postgres resolves the dependency order itself.
        $connection->executeStatement(\sprintf(
            'DROP TABLE IF EXISTS %s CASCADE',
            implode(', ', array_map(
                static function (mixed $table) use ($connection): string {
                    \assert(\is_string($table));

                    return $connection->quoteSingleIdentifier($table);
                },
                $tables,
            )),
        ));
    }
}
