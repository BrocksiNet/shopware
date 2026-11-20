<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Adapter\Database\Sqlite\SqliteDialect;

/**
 * @internal
 */
#[CoversClass(SqliteDialect::class)]
#[CoversClass(SqlDialectFactory::class)]
class SqliteDialectTest extends TestCase
{
    public function testNameAndQuoting(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('sqlite', $dialect->getName());
        static::assertSame('`a``b`', $dialect->quoteIdentifier('a`b'));
    }

    public function testFactoryPicksTheDialectByPlatform(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        static::assertInstanceOf(SqliteDialect::class, SqlDialectFactory::forConnection($connection));
    }

    public function testUpsert(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame(
            'INSERT INTO `t` (`id`, `a`) VALUES (?, ?) ON CONFLICT (`id`) DO UPDATE SET `a` = excluded.`a`',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', ['id'], ['a'])
        );
        static::assertSame(
            'INSERT INTO `t` (`id`) VALUES (?) ON CONFLICT DO NOTHING',
            $dialect->upsert('t', ['id'], '(?)', [], [], true)
        );
        static::assertSame('INSERT INTO `t` (`id`) VALUES (?)', $dialect->upsert('t', ['id'], '(?)'));
    }

    public function testUpsertWithoutConflictTargetFails(): void
    {
        $this->expectException(AdapterException::class);

        (new SqliteDialect())->upsert('t', ['id', 'a'], '(?, ?)', [], ['a']);
    }

    public function testUpsertLooksUpThePrimaryKeyOnce(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAllAssociative')->willReturn([
            ['name' => 'b', 'pk' => '2'],
            ['name' => 'a', 'pk' => '1'],
            ['name' => 'c', 'pk' => '0'],
        ]);
        $dialect = new SqliteDialect($connection);

        $expected = 'INSERT INTO `t` (`a`, `b`, `c`) VALUES (?, ?, ?) ON CONFLICT (`a`, `b`) DO UPDATE SET `c` = excluded.`c`';
        static::assertSame($expected, $dialect->upsert('t', ['a', 'b', 'c'], '(?, ?, ?)', [], ['c']));
        static::assertSame($expected, $dialect->upsert('t', ['a', 'b', 'c'], '(?, ?, ?)', [], ['c']));
    }

    public function testJson(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('(c -> \'$.a\')', $dialect->jsonExtract('c', '$.a'));
        static::assertSame('(c ->> \'$.a\')', $dialect->jsonExtractText('c', '$.a'));
        static::assertSame('CAST((c ->> \'$.a\') AS REAL)', $dialect->jsonValue('c', '$.a', SqlDialect::JSON_TYPE_FLOAT));
        static::assertSame('(c ->> \'$.a\')', $dialect->jsonValue('c', '$.a'));
        static::assertSame('CAST(? AS INTEGER)', $dialect->jsonBind('?', SqlDialect::JSON_BIND_INT));
        static::assertSame('json(?)', $dialect->jsonBind('?', SqlDialect::JSON_BIND_OBJECT));
        static::assertSame('json_set(COALESCE(c, \'{}\'), \'$.a\', ?)', $dialect->jsonSet('c', '$.a', '?'));
    }

    public function testIdsAreBytes(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('lower(hex(p.id))', $dialect->idToText('p.id'));
        static::assertSame(ParameterType::BINARY, $dialect->idParameterType());
        static::assertSame(ArrayParameterType::BINARY, $dialect->idListParameterType());
    }

    public function testMySqlBehaviourFlags(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('group_concat(x, \',\' ORDER BY y)', $dialect->groupConcat('x', ',', 'y'));
        static::assertFalse($dialect->caseInsensitiveStringFilters());
        static::assertSame('DESC', $dialect->sortDirectionWithMySqlNulls('DESC'));
        static::assertSame('x', $dialect->castToFloat('x'));
        static::assertSame('x', $dialect->decimalDivisionScale('x', 4));
        static::assertSame([], $dialect->upsertCollisionColumns('t', ['id']));
        static::assertSame('müller', $dialect->caseInsensitiveValue('MÜLLER'));
        static::assertSame('', $dialect->subqueryFence());
        static::assertStringStartsWith('ROW_NUMBER() OVER (PARTITION BY p', (string) $dialect->rowNumberPerPartition('p'));
    }
}
