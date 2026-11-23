<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\IdCodecColumnTypes;

/**
 * @internal
 */
#[CoversClass(IdCodecColumnTypes::class)]
class IdCodecColumnTypesTest extends TestCase
{
    #[DataProvider('statementProvider')]
    public function testStatementKeepsOrDropsTheCachedTypes(string $statement, bool $keeps): void
    {
        $types = new IdCodecColumnTypes();
        $types->set('SELECT id FROM product', 0, true);

        $types->observe($statement);

        static::assertSame($keeps ? true : null, $types->get('SELECT id FROM product', 0));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statementProvider(): iterable
    {
        yield 'select' => ['SELECT 1', true];
        yield 'lowercase update' => ['update product SET stock = 1', true];
        yield 'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', true];
        yield 'parenthesised select' => ['(SELECT 1) UNION (SELECT 2)', true];
        yield 'hash comment' => ["# cart-persister::load\nSELECT 1", true];
        yield 'dash comment' => ["-- note\n  INSERT INTO t VALUES (1)", true];
        yield 'block comment' => ['/* a */ DELETE FROM t', true];
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2', true];
        yield 'create view' => ['CREATE TEMPORARY VIEW v AS SELECT 1', false];
        yield 'alter table' => ['ALTER TABLE product ALTER COLUMN id TYPE text', false];
        yield 'drop behind comment' => ["# migration\nDROP VIEW v", false];
        yield 'set search path' => ['SET search_path TO other', false];
        yield 'do block' => ['DO $$ BEGIN EXECUTE \'CREATE VIEW v AS SELECT 1\'; END $$', false];
        yield 'rollback to savepoint' => ['ROLLBACK TO SAVEPOINT DOCTRINE_2', false];
        yield 'empty' => ['', false];
    }

    public function testInvalidationReachesEveryConnection(): void
    {
        $first = new IdCodecColumnTypes();
        $second = new IdCodecColumnTypes();
        $first->set('SELECT v FROM t', 0, true);
        $second->set('SELECT v FROM t', 0, false);

        $second->observe('ALTER TABLE t ALTER COLUMN v TYPE text');

        static::assertNull($first->get('SELECT v FROM t', 0));
        static::assertNull($second->get('SELECT v FROM t', 0));
    }

    public function testConnectionsDoNotShareEntries(): void
    {
        $first = new IdCodecColumnTypes();
        $first->set('SELECT v FROM t', 0, true);

        static::assertNull((new IdCodecColumnTypes())->get('SELECT v FROM t', 0));
    }
}
