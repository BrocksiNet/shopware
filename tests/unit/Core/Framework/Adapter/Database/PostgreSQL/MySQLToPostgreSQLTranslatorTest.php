<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
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
}
