<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\MariaDbJsonDialect;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;

/**
 * @internal
 */
#[CoversClass(SqlStorage::class)]
#[CoversClass(SqlQueryCompiler::class)]
#[CoversClass(MariaDbJsonDialect::class)]
class MariaDbStorageTest extends SqlStorageContractTestCase
{
    protected function dialectName(): string
    {
        return 'mariadb';
    }

    protected function url(): string
    {
        return 'mysql://root:root@database:3306/orm_spike';
    }
}
