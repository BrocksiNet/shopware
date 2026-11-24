<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\OpenSearchValkey;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * O1b: OpenSearch answers `filter()`, `aggregate()` and `search()` (ids, total, aggregations, scores), Valkey holds the documents and
 * hydrates the hit ids with one `MGET`. Both engines get every write and every generation step.
 *
 * Order of a swap: the index first, the document pointer second. In the short window between them a hit can name a document the live
 * Valkey generation does not hold yet. The adapter never drops or invents documents, it throws {@see NotSupportedByEngine} and the caller
 * falls back to the DAL.
 *
 * @internal
 */
#[Package('framework')]
class OpenSearchValkeyStorage implements Storage, FilterAware, AggregationAware, SearchAware
{
    private const ENGINE = 'opensearch-valkey';

    /**
     * @param Storage&FilterAware&AggregationAware&SearchAware $index
     */
    public function __construct(
        private readonly Storage $index,
        private readonly Storage $documents,
    ) {
        if (!$index instanceof FilterAware || !$index instanceof AggregationAware || !$index instanceof SearchAware) {
            throw ReadStoreException::invalidCriteria('the index store must answer filters, aggregations and search');
        }
    }

    public function setup(string $generation): void
    {
        $this->index->setup($generation);
        $this->documents->setup($generation);
    }

    public function swap(string $generation): void
    {
        $this->index->swap($generation);
        $this->documents->swap($generation);
    }

    public function liveGeneration(): ?string
    {
        return $this->documents->liveGeneration();
    }

    public function store(array $documents, ?string $generation = null): void
    {
        // documents first: an index hit then always finds its document
        $this->documents->store($documents, $generation);
        $this->index->store($documents, $generation);
    }

    public function remove(array $ids): void
    {
        // index first: no hit is left that names a removed document
        $this->index->remove($ids);
        $this->documents->remove($ids);
    }

    public function get(string $id): ?array
    {
        return $this->documents->get($id);
    }

    public function mget(array $ids): array
    {
        return $this->documents->mget($ids);
    }

    public function clear(): void
    {
        $this->index->clear();
        $this->documents->clear();
    }

    public function destroy(): void
    {
        $this->index->destroy();
        $this->documents->destroy();
    }

    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        return $this->hydrate($this->index()->filter($criteria, $context));
    }

    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        return $this->hydrate($this->index()->search($criteria, $context));
    }

    public function aggregate(StoreCriteria $criteria, StoreContext $context): array
    {
        return $this->index()->aggregate($criteria, $context);
    }

    private function hydrate(StoreResult $hits): StoreResult
    {
        $ids = $hits->ids();
        $loaded = $this->documents->mget($ids);

        $documents = [];
        foreach ($ids as $id) {
            if (!isset($loaded[$id])) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'the index names document ' . $id . ' that the document store does not hold (swap in progress or out of sync)');
            }
            $documents[] = $loaded[$id];
        }

        return new StoreResult($documents, $hits->total, $hits->aggregations, $hits->scores);
    }

    /**
     * @return Storage&FilterAware&AggregationAware&SearchAware
     */
    private function index(): Storage
    {
        \assert($this->index instanceof FilterAware && $this->index instanceof AggregationAware && $this->index instanceof SearchAware);

        return $this->index;
    }
}
