<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Adapter\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlConnectionFactory;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Contains;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\AggregationContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractTestCase;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\FilterContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\SearchContract;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\StorageContract;

/**
 * Runs the read store contract against one SQL engine. The databases are throwaway (`orm_spike` and a SQLite file under
 * `var/switch`), the tables are prefixed `rs_test` and dropped by the contract's `destroy()`.
 * The test is skipped when the engine is not reachable.
 *
 * @internal
 */
abstract class SqlStorageContractTestCase extends ContractTestCase
{
    use AggregationContract;
    use FilterContract;
    use SearchContract;
    use StorageContract;

    /**
     * @return iterable<string, array{StoreCriteria}>
     */
    public static function unsupportedQueries(): iterable
    {
        yield 'contains on a list' => [new StoreCriteria(filters: [new Contains('propertyIds', 'a')])];
        yield 'sort by a list' => [new StoreCriteria(sortings: [new Sorting('propertyIds')])];
        yield 'minimum of a collated string' => [new StoreCriteria(aggregations: [new Min('m', 'name')])];
        yield 'terms on a custom field' => [new StoreCriteria(aggregations: [new Terms('t', 'customFields.swag_color')])];
        yield 'collapse on a string' => [new StoreCriteria(groupField: 'productNumber')];
    }

    #[DataProvider('unsupportedQueries')]
    public function testQueriesWithoutAnExactAnswerAreRejected(StoreCriteria $criteria): void
    {
        $storage = $this->storage;
        static::assertInstanceOf(SqlStorage::class, $storage);

        $this->expectException(NotSupportedByEngine::class);
        $storage->filter($criteria, F::context());
    }

    public function testQueriesNeedALiveGeneration(): void
    {
        $storage = $this->storage;
        static::assertInstanceOf(SqlStorage::class, $storage);
        $storage->destroy();

        $this->expectException(ReadStoreException::class);
        $storage->filter(new StoreCriteria(), F::context());
    }

    public function testAnotherInstanceSeesTheLiveGeneration(): void
    {
        $other = $this->createStorage();

        static::assertSame('g1', $other->liveGeneration());
        static::assertCount(\count(F::ALL), $other->mget(F::ALL));
    }

    abstract protected function dialectName(): string;

    abstract protected function url(): string;

    protected function createStorage(): Storage
    {
        try {
            $connection = SqlConnectionFactory::create($this->url());
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            static::markTestSkipped('The ' . $this->dialectName() . ' database is not reachable: ' . $e->getMessage());
        }

        return new SqlStorage(SqlConnectionFactory::dialect($this->dialectName()), $connection, new FieldResolver(), 'rs_test');
    }
}
