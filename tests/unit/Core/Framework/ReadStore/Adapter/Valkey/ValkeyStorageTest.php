<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Valkey;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * Needs the lane's Valkey (`instance-valkey-1`, database 5, keys below `rs_test_` only).
 *
 * @internal
 */
#[CoversClass(ValkeyStorage::class)]
class ValkeyStorageTest extends ContractTestCase
{
    use StorageContract;

    private const HOST = 'instance-valkey-1';

    private string $prefix = '';

    protected function setUp(): void
    {
        $socket = @fsockopen(self::HOST, 6379, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            static::markTestSkipped('Valkey is not reachable: ' . $errorMessage);
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

    public function testIsAKeyValueStoreOnly(): void
    {
        static::assertNotInstanceOf(FilterAware::class, $this->storage);
    }

    public function testTheKeyPrefixMustBeOurs(): void
    {
        $this->expectException(ReadStoreException::class);
        new ValkeyStorage(self::HOST, 6379, 'shopware');
    }

    public function testDestroyLeavesNoKeysBehind(): void
    {
        $this->storage->setup('g2');
        $this->storage->store([F::documents()[0]], 'g2');
        $this->storage->destroy();

        $redis = new \Redis();
        $redis->connect(self::HOST, 6379);
        $redis->select(ValkeyStorage::DATABASE);
        static::assertSame([], $redis->keys($this->prefix . ':*'));
    }

    protected function createStorage(): Storage
    {
        $this->prefix = 'rs_test_' . bin2hex(random_bytes(4));

        return new ValkeyStorage(self::HOST, 6379, $this->prefix);
    }
}
