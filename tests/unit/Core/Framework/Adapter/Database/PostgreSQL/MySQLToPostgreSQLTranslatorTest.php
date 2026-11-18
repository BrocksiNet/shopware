<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\MySQLToPostgreSQLTranslator;

/**
 * @internal
 */
#[CoversClass(MySQLToPostgreSQLTranslator::class)]
class MySQLToPostgreSQLTranslatorTest extends TestCase
{
    public function testBackticksBecomeDoubleQuotes(): void
    {
        [$sql] = (new MySQLToPostgreSQLTranslator())->translate('SELECT `id` FROM `product`');

        static::assertSame('SELECT "id" FROM "product"', $sql);
    }

    #[DataProvider('groupConcatProvider')]
    public function testGroupConcatSeparatorMovesBeforeOrderBy(string $mysql, string $expected): void
    {
        [$sql] = (new MySQLToPostgreSQLTranslator())->translate($mysql);

        static::assertSame($expected, $sql);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function groupConcatProvider(): iterable
    {
        yield 'order by and separator' => [
            'SELECT GROUP_CONCAT(a.x ORDER BY a.y DESC SEPARATOR \'|\') FROM a',
            'SELECT GROUP_CONCAT(a.x , \'|\' ORDER BY a.y DESC) FROM a',
        ];

        yield 'order by without separator' => [
            'SELECT GROUP_CONCAT(a.x ORDER BY a.y, a.z) FROM a',
            'SELECT GROUP_CONCAT(a.x ORDER BY a.y, a.z) FROM a',
        ];

        yield 'order by null is dropped' => [
            'SELECT GROUP_CONCAT(DISTINCT a.x ORDER BY NULL SEPARATOR \' \') FROM a',
            'SELECT GROUP_CONCAT(DISTINCT a.x , \' \') FROM a',
        ];

        yield 'nested order by is not touched' => [
            'SELECT GROUP_CONCAT((SELECT b.x FROM b ORDER BY b.y LIMIT 1) SEPARATOR \',\') FROM a',
            'SELECT GROUP_CONCAT((SELECT b.x FROM b ORDER BY b.y LIMIT 1) , \',\') FROM a',
        ];

        yield 'two calls' => [
            'SELECT GROUP_CONCAT(x ORDER BY y SEPARATOR \',\'), GROUP_CONCAT(z ORDER BY z DESC SEPARATOR \';\') FROM a',
            'SELECT GROUP_CONCAT(x , \',\' ORDER BY y), GROUP_CONCAT(z , \';\' ORDER BY z DESC) FROM a',
        ];
    }

    public function testQuotedAliasBecomesIdentifier(): void
    {
        [$sql] = (new MySQLToPostgreSQLTranslator())->translate('SELECT 1 AS \'0\'');

        static::assertSame('SELECT 1 AS "0"', $sql);
    }

    public function testReservedWordUserIsQuotedButNotParameterOrFunction(): void
    {
        [$sql] = (new MySQLToPostgreSQLTranslator())->translate('SELECT user.id FROM user WHERE a = :user AND b = user(1)');

        static::assertStringContainsString('"user".id', $sql);
        static::assertStringContainsString('FROM "user"', $sql);
        static::assertStringContainsString(':user', $sql);
        static::assertStringNotContainsString('":user"', $sql);
        static::assertStringContainsString('user(1)', $sql);
        static::assertStringNotContainsString('"user"(', $sql);
    }

    public function testUserTableWithColumnListIsQuoted(): void
    {
        [$sql] = (new MySQLToPostgreSQLTranslator())->translate('INSERT INTO user (id, username) VALUES (?, ?)');

        static::assertSame('INSERT INTO "user" (id, username) VALUES (?, ?)', $sql);
    }

    public function testLongIdentifierIsShortenedAndMapped(): void
    {
        $long = str_repeat('a', 70);

        [$sql, $caseMap] = (new MySQLToPostgreSQLTranslator())->translate('SELECT 1 AS `' . $long . '`');

        static::assertMatchesRegularExpression('/AS "[^"]{1,63}"$/', $sql);
        static::assertStringNotContainsString($long, $sql);
        static::assertContains($long, $caseMap);
    }

    #[DataProvider('translateProvider')]
    public function testTranslate(string $sql, string $expected): void
    {
        [$translated] = (new MySQLToPostgreSQLTranslator())->translate($sql);

        static::assertSame($expected, $translated);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function translateProvider(): iterable
    {
        yield 'binary literal becomes uuid' => [
            'SELECT 0x0123456789abcdef0123456789abcdef',
            'SELECT \'0123456789abcdef0123456789abcdef\'::uuid',
        ];

        yield 'json extract compared to a string' => [
            'SELECT * FROM t WHERE JSON_EXTRACT(c, \'$.a\') = \'x\'',
            'SELECT * FROM t WHERE JSON_EXTRACT(c, \'$.a\') = to_jsonb(\'x\'::text)',
        ];

        yield 'json extract compared to a placeholder' => [
            'SELECT * FROM t WHERE JSON_EXTRACT(c, \'$.a\') = :p',
            'SELECT * FROM t WHERE JSON_EXTRACT(c, \'$.a\') = to_jsonb(:p::text)',
        ];

        yield 'json set with a cast value stays a number' => [
            'UPDATE t SET c = JSON_SET(IFNULL(c, \'{}\'), ?, CAST(? AS SIGNED))',
            'UPDATE t SET c = JSON_SET(IFNULL(c, \'{}\'), ?, CAST(? AS bigint))',
        ];

        yield 'json extract in a list' => [
            'SELECT * FROM t WHERE JSON_EXTRACT(c, \'$.a\') IN (\'a\',\'b\')',
            'SELECT * FROM t WHERE json_extract_text(c, \'$.a\') IN (\'a\',\'b\')',
        ];

        yield 'concat placeholder is typed' => [
            'SELECT CONCAT(:p, \'%\')',
            'SELECT CONCAT(:p::text, \'%\')',
        ];

        yield 'cast as signed' => [
            'SELECT CAST(a AS SIGNED)',
            'SELECT CAST(a AS bigint)',
        ];

        yield 'foreign key checks variable' => [
            'SELECT @@FOREIGN_KEY_CHECKS',
            'SELECT 1',
        ];
    }
}
