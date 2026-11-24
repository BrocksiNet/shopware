<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Base of every adapter's contract test. An adapter test extends it, implements {@see self::createStorage()} and uses the contract trait of
 * every capability the adapter implements: {@see StorageContract}, {@see FilterContract}, {@see AggregationContract}, {@see SearchContract}.
 *
 * Every test starts with a fresh store whose live generation `g1` holds {@see ContractFixtures::documents()} and destroys it afterwards.
 * A query the engine answers with {@see NotSupportedByEngine} marks the test incomplete instead of failing it, so the gaps of an engine show
 * up in the test report.
 *
 * @internal
 */
abstract class ContractTestCase extends TestCase
{
    protected Storage $storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
        $this->storage->destroy();
        $this->storage->setup('g1');
        $this->storage->swap('g1');
        $this->storage->store(ContractFixtures::documents());
    }

    protected function tearDown(): void
    {
        $this->storage->destroy();
    }

    /**
     * A storage with its own namespace (prefix, schema, key space), so parallel runs and other lanes are not touched.
     */
    abstract protected function createStorage(): Storage;

    /**
     * @template T
     *
     * @param \Closure(): T $query
     *
     * @return T
     */
    protected function attempt(\Closure $query): mixed
    {
        try {
            return $query();
        } catch (NotSupportedByEngine $e) {
            static::markTestIncomplete('Not supported by the engine: ' . $e->getMessage());
        }
    }

    protected function filter(StoreCriteria $criteria, ?StoreContext $context = null): StoreResult
    {
        $storage = $this->storage;
        static::assertInstanceOf(FilterAware::class, $storage);

        return $this->attempt(static fn () => $storage->filter($criteria, $context ?? ContractFixtures::context()));
    }

    protected function search(StoreCriteria $criteria, ?StoreContext $context = null): StoreResult
    {
        $storage = $this->storage;
        static::assertInstanceOf(SearchAware::class, $storage);

        return $this->attempt(static fn () => $storage->search($criteria, $context ?? ContractFixtures::context()));
    }

    /**
     * @return array<string, AggregationResult>
     */
    protected function aggregate(StoreCriteria $criteria, ?StoreContext $context = null): array
    {
        $storage = $this->storage;
        static::assertInstanceOf(AggregationAware::class, $storage);

        return $this->attempt(static fn () => $storage->aggregate($criteria, $context ?? ContractFixtures::context()));
    }

    /**
     * @return list<string>
     */
    protected function ids(StoreCriteria $criteria, ?StoreContext $context = null): array
    {
        return $this->filter($criteria, $context)->ids();
    }
}
