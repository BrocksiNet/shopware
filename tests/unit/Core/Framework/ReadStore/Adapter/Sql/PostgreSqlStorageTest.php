<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\PostgreSqlJsonDialect;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;

/**
 * @internal
 */
#[CoversClass(SqlStorage::class)]
#[CoversClass(SqlQueryCompiler::class)]
#[CoversClass(PostgreSqlJsonDialect::class)]
class PostgreSqlStorageTest extends SqlStorageContractTestCase
{
    protected function dialectName(): string
    {
        return 'pgsql';
    }

    protected function url(): string
    {
        return 'pgsql://shopware:shopware@postgres:5432/orm_spike';
    }
}
