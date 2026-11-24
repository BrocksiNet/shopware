<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\OpenSearch;

use OpenSearch\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\OpenSearchStorage;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Elasticsearch\Framework\ClientFactory;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\AggregationContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\FilterContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\SearchContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * Needs the lane's OpenSearch (`opensearch:9200`), uses indices below `rs_test_` only.
 *
 * @internal
 */
#[CoversClass(OpenSearchStorage::class)]
class OpenSearchStorageTest extends ContractTestCase
{
    use AggregationContract;
    use FilterContract;
    use SearchContract;
    use StorageContract;

    private const HOST = 'opensearch';

    private string $prefix = '';

    private ?Client $client = null;

    protected function setUp(): void
    {
        $socket = @fsockopen(self::HOST, 9200, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            static::markTestSkipped('OpenSearch is not reachable: ' . $errorMessage);
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

    public function testThePrefixMustBeOurs(): void
    {
        $this->expectException(ReadStoreException::class);
        new OpenSearchStorage($this->client(), 'shopware_');
    }

    public function testDestroyLeavesNoIndicesBehind(): void
    {
        $this->storage->setup('g2');
        $this->storage->store([F::documents()[0]], 'g2');
        $this->storage->destroy();

        static::assertSame([], $this->client()->cat()->indices(['index' => $this->prefix . '*', 'format' => 'json']));
    }

    protected function createStorage(): Storage
    {
        $this->prefix = 'rs_test_' . bin2hex(random_bytes(4)) . '_';

        return new OpenSearchStorage($this->client(), $this->prefix, 'product', refresh: true);
    }

    private function client(): Client
    {
        return $this->client ??= ClientFactory::createClient('http://' . self::HOST . ':9200', new NullLogger(), false, ['verify_server_cert' => false]);
    }
}
