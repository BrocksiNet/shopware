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
}
