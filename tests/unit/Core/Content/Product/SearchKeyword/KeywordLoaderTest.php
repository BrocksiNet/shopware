<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\SearchKeyword;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\SearchKeyword\KeywordLoader;
use Shopware\Core\Framework\Adapter\Database\Dialect\MySqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\PostgreSqlDialect;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('inventory')]
#[CoversClass(KeywordLoader::class)]
class KeywordLoaderTest extends TestCase
{
    public function testFetch(): void
    {
        $slops = ['foo', 'bar'];

        $tokenSlops = [[
            'normal' => [$slops[0]],
            'reversed' => [$slops[1]],
        ]];

        $connection = static::createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(static::anything(), static::callback(static function (array $params) use ($slops) {
                foreach ($slops as $slop) {
                    static::assertContains($slop, $params);
                }

                return true;
            }));

        (new KeywordLoader($connection, new MySqlDialect()))->fetch($tokenSlops, Context::createDefaultContext());
    }

    public function testFetchLowersTheDictionaryOncePerRowOnPostgreSql(): void
    {
        $tokenSlops = [[
            'normal' => ['foo', 'f_o'],
            'reversed' => ['oof'],
        ]];

        $connection = static::createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(static::callback(static function (string $sql): bool {
                static::assertStringContainsString('lower(keyword) COLLATE ucs_basic AS k', $sql);
                static::assertStringContainsString('OFFSET 0) d', $sql);
                static::assertStringContainsString('d.k LIKE :reg1', $sql);
                static::assertStringContainsString('d.r LIKE :reg3', $sql);
                static::assertStringNotContainsString('lower(keyword) COLLATE ucs_basic LIKE', $sql);
                static::assertSame(1, substr_count($sql, 'language_id = :language'));

                return true;
            }), static::anything());

        (new KeywordLoader($connection, new PostgreSqlDialect($connection)))->fetch($tokenSlops, Context::createDefaultContext());
    }
}
