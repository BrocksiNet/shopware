<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\MySqlJsonDialect;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;

/**
 * @internal
 */
#[CoversClass(SqlStorage::class)]
#[CoversClass(SqlQueryCompiler::class)]
#[CoversClass(MySqlJsonDialect::class)]
class MySqlStorageTest extends SqlStorageContractTestCase
{
    protected function dialectName(): string
    {
        return 'mysql';
    }

    protected function url(): string
    {
        return 'mysql://root:root@mysql:3306/orm_spike';
    }
}
