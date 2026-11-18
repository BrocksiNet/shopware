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
