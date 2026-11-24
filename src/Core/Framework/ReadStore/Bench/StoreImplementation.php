<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ReadStoreScenarios;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\OrmSpike\Harness\ScenarioNotSupported;
use Shopware\Core\Framework\OrmSpike\Harness\StoreLifecycle;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Projector\Rebuilder;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * The read store side of the harness, one service per store (`store-array`, `store-sql-mysql`, `store-opensearch`, ...; tag `orm_spike.implementation`, attribute `key`).
 *
 * Every scenario builds the same DAL {@see Criteria} as `DalImplementation` (R3b through the storefront's `ProductSearchBuilder`, S5 through the
 * Store API `RequestCriteriaBuilder`), converts it with the {@see CriteriaBridge} and runs it on the store. The documents are mapped to the
 * shapes of {@see ResultContract}: translated values of the context language (the maps are resolved with the language chain already),
 * prices of the context currency with the fallback to the default currency like `PriceCollection::getCurrencyPrice()`.
 * So `--verify` against `dal` tests the bridge, the builder and the adapter together, on the criteria the storefront sends.
 *
 * @internal
 */
#[Package('framework')]
class StoreImplementation implements Implementation, ReadStoreScenarios, StoreLifecycle
{
    private ?SalesChannelContext $context = null;

    private bool $group = true;

    public function __construct(
        private readonly StorageRegistry $registry,
        private readonly string $storageKey,
        private readonly CriteriaBridge $bridge,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly RequestCriteriaBuilder $requestCriteriaBuilder,
        private readonly ProductDefinition $productDefinition,
        private readonly ProductSearchBuilderInterface $searchBuilder,
        private readonly Rebuilder $rebuilder,
        private readonly string $builderKey = 'sql',
    ) {
    }

    public static function key(): string
    {
        return 'store';
    }

    public function configure(array $options): void
    {
        $this->group = ($options['group'] ?? '1') !== '0';
    }

    public function s1Listing(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', $params->categoryId));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit($params->limit);
        $criteria->setOffset(($params->page - 1) * $params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $result = $this->run($criteria, $params);
        $language = $this->languageId($params);

        $items = [];
        foreach ($result->documents as $document) {
            $items[] = [
                'id' => $document['id'],
                'productNumber' => $document['productNumber'],
                'name' => self::translated($document['name'] ?? null, $language),
                'manufacturer' => self::translated($document['manufacturer']['name'] ?? null, $language),
                'cover' => $document['cover']['path'] ?? null,
                'price' => self::price($document['price'] ?? null, $params->currencyId),
            ];
        }

        return ['total' => $result->total, 'items' => $items];
    }

    public function s2Detail(Params $params): array
    {
        $document = $this->storage()->get(strtolower($params->variantId));
        if ($document === null || !$this->isAvailable($document, $params)) {
            throw ReadStoreException::invalidCriteria('S2 variant not found or not visible: ' . $params->variantId);
        }

        return $this->detail($document, $params);
    }

    public function r4Mget(Params $params): array
    {
        $ids = array_values(array_map(strtolower(...), $params->mgetIds));
        $documents = $this->storage()->mget($ids);

        $items = [];
        foreach ($ids as $id) {
            $document = $documents[$id] ?? null;
            if ($document === null || !$this->isAvailable($document, $params)) {
                throw ReadStoreException::invalidCriteria('R4 product not found or not visible: ' . $id);
            }
            $items[] = $this->detail($document, $params);
        }

        return ['items' => $items];
    }

    public function s3Search(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('name', $params->searchTerm));
        $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $params->manufacturerIds));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit($params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        self::addPriceAndManufacturerAggregations($criteria);

        $result = $this->run($criteria, $params);
        $language = $this->languageId($params);

        $items = [];
        foreach ($result->documents as $document) {
            $items[] = [
                'id' => $document['id'],
                'productNumber' => $document['productNumber'],
                'name' => self::translated($document['name'] ?? null, $language),
                'manufacturer' => self::translated($document['manufacturer']['name'] ?? null, $language),
                'price' => self::price($document['price'] ?? null, $params->currencyId),
            ];
        }

        return ['total' => $result->total, 'items' => $items, 'aggregations' => self::aggregations($result)];
    }

    public function r3bStorefrontSearch(Params $params): array
    {
        $context = $this->context($params);

        $criteria = new Criteria();
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
        $this->searchBuilder->build(new Request(['search' => $params->searchWord]), $criteria, $context);
        if ($this->group) {
            $criteria->addGroupField(new FieldGrouping('displayGroup'));
            $criteria->addFilter(new NotEqualsFilter('displayGroup', null));
        }
        $criteria->addPostFilter(new EqualsAnyFilter('manufacturerId', $params->manufacturerIds));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit($params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        self::addPriceAndManufacturerAggregations($criteria);

        $result = $this->run($criteria, $params);

        $items = [];
        foreach ($result->documents as $document) {
            $items[] = ['displayGroup' => $document['displayGroup'] ?? null];
        }

        return ['total' => $result->total, 'items' => $items, 'aggregations' => self::aggregations($result)];
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        // @phpstan-ignore shopware.domainException
        throw new ScenarioNotSupported('a read store is written by the projector, not by the harness');
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $context = $this->context($params);
        $criteria = $this->requestCriteriaBuilder->fromArray($params->criteriaJson, new Criteria(), $this->productDefinition, $context->getContext());

        $result = $this->run($criteria, $params);
        $language = $this->languageId($params);

        $items = [];
        foreach ($result->documents as $document) {
            $items[] = [
                'id' => $document['id'],
                'productNumber' => $document['productNumber'],
                'manufacturer' => self::translated($document['manufacturer']['name'] ?? null, $language),
            ];
        }

        return ['total' => $result->total, 'items' => $items];
    }

    public function rebuild(): array
    {
        $result = $this->rebuilder->rebuild($this->storage(), $this->registry->builder($this->builderKey));

        return ['documents' => $result['documents'], 'bytes' => null];
    }

    public function flush(): void
    {
        // the port has no refresh: SQL, Array, MongoDB and Valkey are read-your-writes, OpenSearch searches see a write after its
        // `refresh_interval` (1 s) unless the adapter is built with `refresh: true`; `get()` (R6) is realtime in OpenSearch either way
    }

    public function currentName(Params $params, string $productId): ?string
    {
        $document = $this->storage()->get(strtolower($productId));

        return $document === null ? null : self::translated($document['name'] ?? null, $this->languageId($params));
    }

    /**
     * Runs a DAL criteria on the store, through the bridge.
     */
    public function run(Criteria $criteria, Params $params): StoreResult
    {
        $bridged = $this->bridge->convert($criteria, $this->context($params));
        $storage = $this->storage();

        if ($bridged->criteria->search !== null) {
            if (!$storage instanceof SearchAware) {
                // @phpstan-ignore shopware.domainException
                throw new ScenarioNotSupported(\sprintf('store %s has no search', $this->storageKey));
            }

            return $storage->search($bridged->criteria, $bridged->context);
        }

        if (!$storage instanceof FilterAware) {
            // @phpstan-ignore shopware.domainException
            throw new ScenarioNotSupported(\sprintf('store %s has no filter', $this->storageKey));
        }

        return $storage->filter($bridged->criteria, $bridged->context);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function detail(array $document, Params $params): array
    {
        $language = $this->languageId($params);

        $prices = [];
        foreach ($document['prices'] ?? [] as $price) {
            $prices[] = [
                'quantityStart' => $price['quantityStart'],
                'quantityEnd' => $price['quantityEnd'],
                'price' => self::price($price['price'] ?? null, $params->currencyId),
            ];
        }
        usort($prices, static fn (array $a, array $b): int => $a['quantityStart'] <=> $b['quantityStart']);

        $options = [];
        foreach ($document['options'] ?? [] as $option) {
            $options[] = [
                'group' => (string) self::translated($option['group']['name'] ?? null, $language),
                'name' => (string) self::translated($option['name'] ?? null, $language),
            ];
        }
        usort($options, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        $categories = array_values(array_map(ResultContract::hex(...), $document['categoryIds'] ?? []));
        sort($categories);

        return [
            'id' => $document['id'],
            'productNumber' => $document['productNumber'],
            'name' => self::translated($document['name'] ?? null, $language),
            'description' => self::translated($document['description'] ?? null, $language),
            'manufacturerId' => $document['manufacturerId'] ?? null,
            'manufacturer' => self::translated($document['manufacturer']['name'] ?? null, $language),
            'cover' => $document['cover']['path'] ?? null,
            'price' => self::price($document['price'] ?? null, $params->currencyId),
            'prices' => $prices,
            'options' => $options,
            'categories' => $categories,
        ];
    }

    private static function addPriceAndManufacturerAggregations(Criteria $criteria): void
    {
        $criteria->addAggregation(new MinAggregation('minPrice', 'price.gross'));
        $criteria->addAggregation(new MaxAggregation('maxPrice', 'price.gross'));
        $criteria->addAggregation(new TermsAggregation('manufacturers', 'manufacturerId'));
    }

    /**
     * @return array{minPrice: ?string, maxPrice: ?string, manufacturers: array<string, int>}
     */
    private static function aggregations(StoreResult $result): array
    {
        $min = $result->aggregations['minPrice'] ?? null;
        $max = $result->aggregations['maxPrice'] ?? null;
        $terms = $result->aggregations['manufacturers'] ?? null;

        $manufacturers = [];
        if ($terms instanceof TermsResult) {
            foreach ($terms->counts() as $key => $count) {
                $manufacturers[ResultContract::hex((string) $key)] = $count;
            }
        }
        ksort($manufacturers);

        return [
            'minPrice' => $min instanceof MetricResult ? ResultContract::price($min->value) : null,
            'maxPrice' => $max instanceof MetricResult ? ResultContract::price($max->value) : null,
            'manufacturers' => $manufacturers,
        ];
    }

    /**
     * The gross price of the currency, else of the default currency (`PriceCollection::getCurrencyPrice()` with fallback).
     */
    private static function price(mixed $list, string $currencyId): ?string
    {
        if (!\is_array($list)) {
            return null;
        }
        $fallback = null;
        foreach ($list as $price) {
            $id = strtolower((string) ($price['currencyId'] ?? ''));
            if ($id === strtolower($currencyId)) {
                return ResultContract::price($price['gross'] ?? null);
            }
            if ($id === Defaults::CURRENCY) {
                $fallback = $price['gross'] ?? null;
            }
        }

        return ResultContract::price($fallback);
    }

    private static function translated(mixed $map, string $languageId): ?string
    {
        if (!\is_array($map)) {
            return null;
        }
        $value = $map[$languageId] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Same check as `ProductAvailableFilter` with `VISIBILITY_LINK`, applied in PHP after `Storage::get()` / `Storage::mget()`.
     *
     * @param array<string, mixed> $document
     */
    private function isAvailable(array $document, Params $params): bool
    {
        if (($document['active'] ?? false) !== true) {
            return false;
        }

        $visibility = $document['visibilities'][strtolower($params->salesChannelId)] ?? null;

        return \is_int($visibility) && $visibility >= ProductVisibilityDefinition::VISIBILITY_LINK;
    }

    private function languageId(Params $params): string
    {
        return strtolower($this->context($params)->getLanguageId());
    }

    private function storage(): Storage
    {
        return $this->registry->storage($this->storageKey);
    }

    private function context(Params $params): SalesChannelContext
    {
        return $this->context ??= $this->contextFactory->create(Uuid::randomHex(), $params->salesChannelId, [
            SalesChannelContextService::LANGUAGE_ID => $params->languageId,
            SalesChannelContextService::CURRENCY_ID => $params->currencyId,
        ]);
    }
}
