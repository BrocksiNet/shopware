<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqliteJsonDialect;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;

/**
 * @internal
 */
#[CoversClass(SqlStorage::class)]
#[CoversClass(SqlQueryCompiler::class)]
#[CoversClass(SqliteJsonDialect::class)]
class SqliteStorageTest extends SqlStorageContractTestCase
{
    protected function dialectName(): string
    {
        return 'sqlite';
    }

    protected function url(): string
    {
        return 'sqlite:////var/www/html/var/switch/rs_test.sqlite';
    }
}
