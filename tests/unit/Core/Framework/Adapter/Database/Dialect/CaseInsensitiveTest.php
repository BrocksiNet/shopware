<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\Dialect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\Dialect\MySqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\PostgreSqlDialect;
use Shopware\Core\Framework\Adapter\Database\Sqlite\SqliteDialect;

/**
 * @internal
 */
#[CoversClass(MySqlDialect::class)]
#[CoversClass(PostgreSqlDialect::class)]
#[CoversClass(SqliteDialect::class)]
class CaseInsensitiveTest extends TestCase
{
    public function testMySqlKeepsColumn(): void
    {
        static::assertSame('keyword', (new MySqlDialect())->caseInsensitive('keyword'));
    }

    public function testPostgreSqlLowersWithDeterministicCollation(): void
    {
        static::assertSame('lower(keyword) COLLATE ucs_basic', (new PostgreSqlDialect())->caseInsensitive('keyword'));
    }

    public function testSqliteLowers(): void
    {
        static::assertSame('lower(keyword)', (new SqliteDialect())->caseInsensitive('keyword'));
    }

    public function testValueIsKeptByMySqlAndLoweredByTheOthers(): void
    {
        static::assertSame('Äpfel ABC', (new MySqlDialect())->caseInsensitiveValue('Äpfel ABC'));
        static::assertSame('äpfel abc', (new PostgreSqlDialect())->caseInsensitiveValue('Äpfel ABC'));
        static::assertSame('äpfel abc', (new SqliteDialect())->caseInsensitiveValue('Äpfel ABC'));
    }

    public function testDecimalDivisionScale(): void
    {
        static::assertSame('(a / b)', (new MySqlDialect())->decimalDivisionScale('(a / b)', 4));
        static::assertSame('CAST((a / b) AS NUMERIC(30, 4))', (new PostgreSqlDialect())->decimalDivisionScale('(a / b)', 4));
        static::assertSame('(a / b)', (new SqliteDialect())->decimalDivisionScale('(a / b)', 4));
    }

    public function testCastToFloat(): void
    {
        static::assertSame('x', (new MySqlDialect())->castToFloat('x'));
        static::assertSame('CAST(x AS double precision)', (new PostgreSqlDialect())->castToFloat('x'));
        static::assertSame('x', (new SqliteDialect())->castToFloat('x'));
    }
}
