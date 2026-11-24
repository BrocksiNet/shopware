<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\OpenSearchValkey;

use OpenSearch\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\OpenSearchStorage;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearchValkey\OpenSearchValkeyStorage;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Elasticsearch\Framework\ClientFactory;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\AggregationContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\FilterContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\SearchContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * Needs the lane's OpenSearch (`opensearch:9200`, indices below `rs_test_`) and Valkey (`instance-valkey-1`, database 5, keys below `rs_test_`).
 *
 * @internal
 */
#[CoversClass(OpenSearchValkeyStorage::class)]
class OpenSearchValkeyStorageTest extends ContractTestCase
{
    use AggregationContract;
    use FilterContract;
    use SearchContract;
    use StorageContract;

    private ?Client $client = null;

    private Storage $indexStore;

    protected function setUp(): void
    {
        foreach ([['opensearch', 9200], ['instance-valkey-1', 6379]] as [$host, $port]) {
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1);
            if ($socket === false) {
                static::markTestSkipped($host . ' is not reachable: ' . $errorMessage);
            }
            fclose($socket);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        parent::tearDown();
    }

    public function testAHitWithoutDocumentFallsBackInsteadOfDroppingIt(): void
    {
        $storage = $this->storage;
        static::assertInstanceOf(OpenSearchValkeyStorage::class, $storage);

        // write one document to the index only
        $extra = F::documents()[0];
        $extra['id'] = 'ff000000000000000000000000000fff';
        $extra['productNumber'] = 'only-in-index';
        $this->indexStore->store([$extra]);

        $this->expectException(NotSupportedByEngine::class);
        $storage->filter(new StoreCriteria(), F::context());
    }

    protected function createStorage(): Storage
    {
        $suffix = bin2hex(random_bytes(4));
        $this->client ??= ClientFactory::createClient('http://opensearch:9200', new NullLogger(), false, ['verify_server_cert' => false]);

        $this->indexStore = new OpenSearchStorage($this->client, 'rs_test_' . $suffix . '_', 'product', refresh: true);

        return new OpenSearchValkeyStorage(
            $this->indexStore,
            new ValkeyStorage('instance-valkey-1', 6379, 'rs_test_' . $suffix),
        );
    }
}
