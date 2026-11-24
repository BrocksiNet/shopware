<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\MongoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbDocument;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbStorage;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\AggregationContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\FilterContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\SearchContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * Needs the lane's MongoDB (`instance-mongodb-1`, databases `rs_test_...` only, dropped in tearDown).
 *
 * @internal
 */
#[CoversClass(MongoDbStorage::class)]
#[CoversClass(MongoDbQueryCompiler::class)]
#[CoversClass(MongoDbDocument::class)]
class MongoDbStorageTest extends ContractTestCase
{
    use AggregationContract;
    use FilterContract;
    use SearchContract;
    use StorageContract;

    private const HOST = 'instance-mongodb-1';

    protected function setUp(): void
    {
        $socket = @fsockopen(self::HOST, 27017, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            static::markTestSkipped('MongoDB is not reachable: ' . $errorMessage);
        }
        fclose($socket);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        parent::tearDown();
    }

    public function testTheDatabaseNameMustBeOurs(): void
    {
        $this->expectException(ReadStoreException::class);
        new MongoDbStorage('mongodb://' . self::HOST . ':27017', 'shopware');
    }

    protected function createStorage(): Storage
    {
        return new MongoDbStorage('mongodb://' . self::HOST . ':27017', 'rs_test_' . bin2hex(random_bytes(4)));
    }
}
