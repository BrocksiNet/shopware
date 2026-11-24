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
    #[DataProvider('intervalProvider')]
    public function testIntervalWithPlaceholderOrNumber(string $mysql, string $expected): void
    {
        static::assertSame($expected, (new MySQLToPostgreSQLTranslator())->translate($mysql)[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function intervalProvider(): iterable
    {
        yield 'named placeholder' => [
            'UPDATE t SET d = DATE_ADD(d, INTERVAL :days DAY)',
            'UPDATE t SET d = DATE_ADD(d, (:days * INTERVAL \'1 day\'))',
        ];
        yield 'number and lower case' => [
            'SELECT DATE_SUB(d, interval 2 hour)',
            'SELECT DATE_SUB(d, (2 * INTERVAL \'1 hour\'))',
        ];
        yield 'quarter' => [
            'SELECT DATE_ADD(d, INTERVAL ? QUARTER)',
            'SELECT DATE_ADD(d, (? * INTERVAL \'3 month\'))',
        ];
        yield 'postgres interval literal stays' => [
            'SELECT d + INTERVAL \'1 day\'',
            'SELECT d + INTERVAL \'1 day\'',
        ];
    }

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

    public function testQuotedLowerCaseAliasIsNotRenamedToAKeyword(): void
    {
        // shape of DBAL's PostgreSQLSchemaManager::selectIndexColumns(), which reads the key "where"
        $translator = new MySQLToPostgreSQLTranslator();

        [, $caseMap] = $translator->translate('SELECT c.relname, pg_get_expr(i.indpred, i.indrelid) AS "where" FROM pg_index i JOIN information_schema.tables t ON t.table_name = c.relname WHERE c.relname = ?');
        static::assertArrayNotHasKey('where', $caseMap);

        [, $caseMap] = $translator->translate('SELECT TABLE_NAME FROM information_schema.tables WHERE x IN (SELECT 1 AS "where")');
        static::assertArrayNotHasKey('where', $caseMap);
        static::assertSame('TABLE_NAME', $caseMap['table_name'] ?? null);
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

    public function testHashOperatorsAreKeptAndMysqlCommentsStripped(): void
    {
        $translator = new MySQLToPostgreSQLTranslator();

        static::assertSame('SELECT a #>> \'{}\' FROM t', $translator->translate('SELECT a #>> \'{}\' FROM t')[0]);
        static::assertSame('SELECT a #> \'{x}\' FROM t', $translator->translate('SELECT a #> \'{x}\' FROM t')[0]);
        static::assertSame("SELECT 1 \nFROM t", $translator->translate("SELECT 1 # comment #> here\nFROM t")[0]);
        static::assertSame('SELECT 1 ', $translator->translate('SELECT 1 # trailing comment')[0]);
    }

    #[DataProvider('quotedIdentifierProvider')]
    public function testDoubleQuotedIdentifiersStayIdentifiers(string $in, string $expected): void
    {
        static::assertSame($expected, (new MySQLToPostgreSQLTranslator())->translate($in)[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quotedIdentifierProvider(): iterable
    {
        yield 'qualified column' => ['SELECT "product"."id" FROM "product"', 'SELECT "product"."id" FROM "product"'];
        yield 'where and order' => [
            'SELECT 1 FROM "t" WHERE "a" = 1 AND "b.c" = 2 ORDER BY "a" DESC',
            'SELECT 1 FROM "t" WHERE "a" = 1 AND "b.c" = 2 ORDER BY "a" DESC',
        ];
        yield 'upsert' => [
            'INSERT INTO "t" ("id", "a") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "a" = EXCLUDED."a"',
            'INSERT INTO "t" ("id", "a") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "a" = EXCLUDED."a"',
        ];
        yield 'string literal after operator stays a string' => [
            'SELECT 1 FROM t WHERE a = "x" AND b IN ("y", "z")',
            'SELECT 1 FROM t WHERE a = \'x\' AND b IN (\'y\', \'z\')',
        ];
        yield 'select list and tuple comparison' => [
            'SELECT 1 as "exists", "media_id", "language_id" FROM "media_translation" WHERE ("media_id","language_id") IN ((?,?))',
            'SELECT 1 as "exists", "media_id", "language_id" FROM "media_translation" WHERE ("media_id","language_id") IN ((?,?))',
        ];
        yield 'first function argument with a dotted alias' => [
            'SELECT COALESCE("a.b.name","a.c.name") as "a.name" FROM t',
            'SELECT COALESCE("a.b.name","a.c.name") as "a.name" FROM t',
        ];
        yield 'first argument of a null handling function' => [
            'UPDATE "t" SET "c" = JSON_SET(COALESCE("c", \'{}\'), \'$.a\', ?) WHERE "id" = ?',
            'UPDATE "t" SET "c" = JSON_SET(COALESCE("c", \'{}\'), \'$.a\', ?) WHERE "id" = ?',
        ];
        yield 'update set list' => [
            'UPDATE "tax" SET "tax_rate" = ?, "name" = ?, "updated_at" = ? WHERE id = ?',
            'UPDATE "tax" SET "tax_rate" = ?, "name" = ?, "updated_at" = ? WHERE id = ?',
        ];
        yield 'json path stays a string' => [
            'SELECT JSON_EXTRACT(a, "$.b") FROM t',
            'SELECT JSON_EXTRACT(a, \'$.b\') FROM t',
        ];
    }

    public function testQuotedIdentifierLongerThan63BytesIsShortenedLikeABacktickOne(): void
    {
        $name = str_repeat('a', 70);
        $translator = new MySQLToPostgreSQLTranslator();

        [$quoted, $quotedMap] = $translator->translate('SELECT "t"."x" AS "' . $name . '" FROM "t"');
        [$backtick, $backtickMap] = $translator->translate('SELECT `t`.`x` AS `' . $name . '` FROM `t`');

        static::assertSame($backtick, $quoted);
        static::assertSame($backtickMap, $quotedMap);
        static::assertSame([$name], array_values($quotedMap));
    }

    public function testTranslationIsSharedBetweenInstancesThroughApcu(): void
    {
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            static::markTestSkipped('APCu is not enabled for the CLI (apc.enable_cli)');
        }

        $sql = 'SELECT `t`.`x` AS `a_' . bin2hex(random_bytes(4)) . '` FROM `t` WHERE `t`.`y` <=> :y';
        $first = (new MySQLToPostgreSQLTranslator())->translate($sql);

        $stored = array_map(
            static fn (array $entry): mixed => apcu_fetch($entry['key']),
            iterator_to_array(new \APCUIterator('/^sw_pg_translation_/'), false)
        );
        static::assertContains($first, $stored);
        static::assertSame($first, (new MySQLToPostgreSQLTranslator())->translate($sql));
    }
}
