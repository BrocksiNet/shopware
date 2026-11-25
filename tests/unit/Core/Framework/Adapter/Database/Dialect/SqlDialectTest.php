<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Adapter\Database\Dialect\MariaDbDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\MySqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\PostgreSqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;

/**
 * @internal
 */
#[CoversClass(MySqlDialect::class)]
#[CoversClass(MariaDbDialect::class)]
#[CoversClass(PostgreSqlDialect::class)]
#[CoversClass(SqlDialectFactory::class)]
class SqlDialectTest extends TestCase
{
    /**
     * @return iterable<string, array{SqlDialect, string}>
     */
    public static function quoteProvider(): iterable
    {
        yield 'mysql' => [new MySqlDialect(), '`product`'];
        yield 'mariadb' => [new MariaDbDialect(), '`product`'];
        yield 'postgresql' => [new PostgreSqlDialect(), '"product"'];
    }

    #[DataProvider('quoteProvider')]
    public function testQuoteIdentifier(SqlDialect $dialect, string $expected): void
    {
        static::assertSame($expected, $dialect->quoteIdentifier('product'));
    }

    public function testQuoteIdentifierEscapesTheQuoteCharacter(): void
    {
        static::assertSame('`a``b`', (new MySqlDialect())->quoteIdentifier('a`b'));
        static::assertSame('"a""b"', (new PostgreSqlDialect())->quoteIdentifier('a"b'));
    }

    public function testPostgreSqlQuotesWithBackticksForTheTranslatorOnRequest(): void
    {
        $dialect = new PostgreSqlDialect(null, false);

        static::assertSame('`product`', $dialect->quoteIdentifier('product'));
        static::assertSame(
            'INSERT INTO `t` (`id`, `a`) VALUES (?, ?) ON CONFLICT (`id`) DO UPDATE SET `a` = EXCLUDED.`a`',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', ['id'], ['a'])
        );
    }

    public function testNames(): void
    {
        static::assertSame('mysql', (new MySqlDialect())->getName());
        static::assertSame('mariadb', (new MariaDbDialect())->getName());
        static::assertSame('postgresql', (new PostgreSqlDialect())->getName());
    }

    public function testUpsertMySql(): void
    {
        $dialect = new MySqlDialect();

        static::assertSame(
            'INSERT INTO `t` (`id`, `a`) VALUES (?, ?)',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)')
        );
        static::assertSame(
            'INSERT IGNORE INTO `t` (`id`, `a`) VALUES (?, ?)',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', [], [], true)
        );
        static::assertSame(
            'INSERT INTO `t` (`id`, `a`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`)',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', ['id'], ['a'])
        );
    }

    public function testUpsertPostgreSql(): void
    {
        $dialect = new PostgreSqlDialect();

        static::assertSame(
            'INSERT INTO "t" ("id", "a") VALUES (?, ?)',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)')
        );
        static::assertSame(
            'INSERT INTO "t" ("id", "a") VALUES (?, ?) ON CONFLICT DO NOTHING',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', [], [], true)
        );
        static::assertSame(
            'INSERT INTO "t" ("id", "a") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "a" = EXCLUDED."a"',
            $dialect->upsert('t', ['id', 'a'], '(?, ?)', ['id'], ['a'])
        );
    }

    public function testUpsertPostgreSqlLooksUpThePrimaryKeyOnce(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchFirstColumn')->willReturn(['id', 'version_id']);

        $dialect = new PostgreSqlDialect($connection, true);
        $expected = 'INSERT INTO "t" ("id", "a") VALUES (?, ?) ON CONFLICT ("id", "version_id") DO UPDATE SET "a" = EXCLUDED."a"';

        static::assertSame($expected, $dialect->upsert('t', ['id', 'a'], '(?, ?)', [], ['a']));
        static::assertSame($expected, $dialect->upsert('t', ['id', 'a'], '(?, ?)', [], ['a']));
    }

    public function testUpsertPostgreSqlWithoutConflictTargetFails(): void
    {
        $this->expectException(AdapterException::class);

        (new PostgreSqlDialect())->upsert('t', ['id', 'a'], '(?, ?)', [], ['a']);
    }

    public function testJsonMySql(): void
    {
        $dialect = new MySqlDialect();

        static::assertSame('JSON_EXTRACT(`t`.`c`, \'$.a.b\')', $dialect->jsonExtract('`t`.`c`', '$.a.b'));
        static::assertSame('JSON_UNQUOTE(JSON_EXTRACT(`c`, \'$.a\'))', $dialect->jsonExtractText('`c`', '$.a'));
        static::assertSame('JSON_UNQUOTE(JSON_EXTRACT(`c`, \'$.a\'))', $dialect->jsonExtractNumber('`c`', '$.a'));
        static::assertSame('JSON_SET(IFNULL(`c`, "{}"), \'$."a"\', ?)', $dialect->jsonSet('`c`', '$."a"', '?'));
        static::assertSame('JSON_REMOVE(`c`, JSON_UNQUOTE(JSON_SEARCH(`c`, \'one\', ?)))', $dialect->jsonArrayRemoveString('`c`', '?'));
        static::assertSame('JSON_CONTAINS(`c`, JSON_QUOTE(?))', $dialect->jsonArrayContainsString('`c`', '?'));
        static::assertSame(
            'IF(JSON_TYPE(JSON_EXTRACT(`c`, \'$.a\')) != "NULL", CAST(JSON_UNQUOTE(JSON_EXTRACT(`c`, \'$.a\')) AS DATE), NULL)',
            $dialect->jsonValue('`c`', '$.a', SqlDialect::JSON_TYPE_DATE)
        );
        static::assertStringContainsString('COLLATE utf8mb4_unicode_ci', $dialect->jsonValue('`c`', '$.a'));
        static::assertStringContainsString('+ 0.0', $dialect->jsonValue('`c`', '$.a', SqlDialect::JSON_TYPE_FLOAT));
        static::assertStringContainsString('datetime(3)', $dialect->jsonValue('`c`', '$.a', SqlDialect::JSON_TYPE_DATETIME));
        static::assertStringContainsString('"true"', $dialect->jsonValue('`c`', '$.a', SqlDialect::JSON_TYPE_BOOL));
        static::assertSame('JSON_EXTRACT(`c`, \'$.a\'\'b\')', $dialect->jsonExtract('`c`', '$.a\'b'));
    }

    public function testJsonPostgreSql(): void
    {
        $dialect = new PostgreSqlDialect();

        static::assertSame('jsonb_path_query_first("t"."c", \'$.a.b\'::jsonpath)', $dialect->jsonExtract('"t"."c"', '$.a.b'));
        static::assertSame('(jsonb_build_array(jsonb_path_query_first(c, \'$.a\'::jsonpath)) ->> 0)', $dialect->jsonExtractText('c', '$.a'));
        static::assertSame('((jsonb_build_array(jsonb_path_query_first((c)::jsonb, \'$.a\'::jsonpath)) ->> 0)::numeric)', $dialect->jsonExtractNumber('c', '$.a'));
        static::assertSame(
            'jsonb_set(COALESCE(c, \'{}\'::jsonb), \'{"a","b-c"}\'::text[], ?, true)',
            $dialect->jsonSet('c', '$."a"."b-c"', '?')
        );
        static::assertSame(
            'jsonb_set(COALESCE(c, \'{}\'::jsonb), \'{"a","b"}\'::text[], ?, true)',
            $dialect->jsonSet('c', '$.a.b', '?')
        );
        static::assertSame('(c - CAST(? AS text))', $dialect->jsonArrayRemoveString('c', '?'));
        static::assertSame('(c @> jsonb_build_array(CAST(? AS text)))', $dialect->jsonArrayContainsString('c', '?'));
        static::assertStringEndsWith('::numeric', $dialect->jsonValue('c', '$.a', SqlDialect::JSON_TYPE_FLOAT));
        static::assertStringEndsWith('::timestamp(3)', $dialect->jsonValue('c', '$.a', SqlDialect::JSON_TYPE_DATETIME));
        static::assertStringEndsWith('::date', $dialect->jsonValue('c', '$.a', SqlDialect::JSON_TYPE_DATE));
        static::assertStringStartsWith('(CASE WHEN', $dialect->jsonValue('c', '$.a', SqlDialect::JSON_TYPE_BOOL));
        static::assertStringNotContainsString('IF(', $dialect->jsonValue('c', '$.a'));
    }

    public function testJsonBind(): void
    {
        $mysql = new MySqlDialect();
        static::assertSame('?', $mysql->jsonBind('?', SqlDialect::JSON_BIND_STRING));
        static::assertSame('?', $mysql->jsonBind('?', SqlDialect::JSON_BIND_INT));
        static::assertSame('? + 0.0', $mysql->jsonBind('?', SqlDialect::JSON_BIND_FLOAT));
        static::assertSame('true', $mysql->jsonBind('true', SqlDialect::JSON_BIND_BOOL));
        static::assertSame('JSON_MERGE("{}", ?)', $mysql->jsonBind('?', SqlDialect::JSON_BIND_OBJECT));
        static::assertSame('JSON_MERGE("[]", ?)', $mysql->jsonBind('?', SqlDialect::JSON_BIND_ARRAY));

        $pg = new PostgreSqlDialect();
        static::assertSame('to_jsonb(?::text)', $pg->jsonBind('?', SqlDialect::JSON_BIND_STRING));
        static::assertSame('to_jsonb(?::bigint)', $pg->jsonBind('?', SqlDialect::JSON_BIND_INT));
        static::assertSame('to_jsonb(?::numeric)', $pg->jsonBind('?', SqlDialect::JSON_BIND_FLOAT));
        static::assertSame('true::text::jsonb', $pg->jsonBind('true', SqlDialect::JSON_BIND_BOOL));
        static::assertSame('?::text::jsonb', $pg->jsonBind('?', SqlDialect::JSON_BIND_OBJECT));
        static::assertSame('?::text::jsonb', $pg->jsonBind('?', SqlDialect::JSON_BIND_ARRAY));
    }

    public function testIds(): void
    {
        static::assertSame('LOWER(HEX(`p`.`id`))', (new MySqlDialect())->idToText('`p`.`id`'));
        static::assertSame('REPLACE("p"."id"::text, \'-\', \'\')', (new PostgreSqlDialect())->idToText('"p"."id"'));

        foreach ([new MySqlDialect(), new MariaDbDialect()] as $dialect) {
            static::assertSame(ParameterType::BINARY, $dialect->idParameterType());
            static::assertSame(ArrayParameterType::BINARY, $dialect->idListParameterType());
        }

        // PostgreSQL binds ids as hex strings
        static::assertSame(ParameterType::STRING, (new PostgreSqlDialect())->idParameterType());
        static::assertSame(ArrayParameterType::STRING, (new PostgreSqlDialect())->idListParameterType());
    }

    public function testGroupConcat(): void
    {
        $mysql = new MySqlDialect();
        static::assertSame('GROUP_CONCAT(x SEPARATOR \',\')', $mysql->groupConcat('x'));
        static::assertSame('GROUP_CONCAT(HEX(x) ORDER BY y DESC SEPARATOR \'||\')', $mysql->groupConcat('HEX(x)', '||', 'y DESC'));

        $pg = new PostgreSqlDialect();
        static::assertSame('string_agg((x)::text, \',\')', $pg->groupConcat('x'));
        static::assertSame('string_agg((x)::text, \'||\' ORDER BY y DESC)', $pg->groupConcat('x', '||', 'y DESC'));
    }

    public function testFactoryPicksTheDialectByPlatform(): void
    {
        static::assertInstanceOf(PostgreSqlDialect::class, SqlDialectFactory::forConnection($this->connection(new PostgreSQLPlatform())));
        static::assertInstanceOf(MariaDbDialect::class, SqlDialectFactory::forConnection($this->connection(new MariaDBPlatform())));
        $mysql = SqlDialectFactory::forConnection($this->connection(new MySQLPlatform()));
        static::assertInstanceOf(MySqlDialect::class, $mysql);
        static::assertNotInstanceOf(MariaDbDialect::class, $mysql);
    }

    private function connection(object $platform): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
