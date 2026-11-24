<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\ArrayStore;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\ReadStore\Adapter\ArrayStore\ArrayStorage;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\AggregationContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\FilterContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\SearchContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * @internal
 */
#[CoversClass(ArrayStorage::class)]
#[CoversClass(QueryEvaluator::class)]
#[CoversClass(FieldResolver::class)]
class ArrayStorageTest extends ContractTestCase
{
    use AggregationContract;
    use FilterContract;
    use SearchContract;
    use StorageContract;

    private ?string $directory = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->directory !== null && is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function testFilterRejectsASearchTerm(): void
    {
        $storage = $this->storage;
        static::assertInstanceOf(ArrayStorage::class, $storage);

        $this->expectException(NotSupportedByEngine::class);
        $storage->filter(new StoreCriteria(search: new SearchTerm('x', [], [])), F::context());
    }

    public function testPersistedGenerationsAreSharedBetweenInstances(): void
    {
        $this->directory = sys_get_temp_dir() . '/read-store-array-' . bin2hex(random_bytes(4));
        $writer = new ArrayStorage($this->directory);
        $reader = new ArrayStorage($this->directory);

        $writer->setup('g1');
        $writer->swap('g1');
        $writer->store(F::documents());
        static::assertSame('g1', $reader->liveGeneration());
        static::assertSame([F::S1], $reader->filter(new StoreCriteria(filters: [new Equals('productNumber', 'SW-S1')]), F::context())->ids());

        // a rebuild writes the pending generation at swap(), readers see it afterwards
        $writer->setup('g2');
        $writer->store([F::documents()[0]], 'g2');
        static::assertCount(\count(F::ALL), $reader->mget(F::ALL));
        $writer->swap('g2');
        static::assertSame('g2', $reader->liveGeneration());
        static::assertSame([F::S1], array_keys($reader->mget(F::ALL)));
        static::assertFileDoesNotExist($this->directory . '/array-g1.json');

        $writer->destroy();
        static::assertNull($reader->liveGeneration());
    }

    protected function createStorage(): Storage
    {
        return new ArrayStorage();
    }
}
