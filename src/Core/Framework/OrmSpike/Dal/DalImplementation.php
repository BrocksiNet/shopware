<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Dal;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Reference implementation 1: what Shopware does today.
 *
 * Reads go through the services the storefront uses: `sales_channel.product.repository` ({@see SalesChannelRepository}) with a
 * {@see SalesChannelContext} created by the sales channel context factory for the language chain [de-DE, en-GB]. The sales channel
 * definition adds the `ProductAvailableFilter` (visibility >= 10 for the sales channel, active) and the associations `prices`, `unit`,
 * `deliveryTime`, `cover.media` and `tax` on its own, like in a storefront request. S5 uses the Store API `RequestCriteriaBuilder`
 * (with `ApiCriteriaValidator`). S4 uses `product.repository` `create` and `update` with the indexers switched off (`DISABLE_INDEXING`).
 *
 * @internal
 */
#[Package('framework')]
final class DalImplementation implements Implementation
{
    private ?SalesChannelContext $context = null;

    public function __construct(
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $productRepository,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly RequestCriteriaBuilder $requestCriteriaBuilder,
        private readonly ProductDefinition $productDefinition,
    ) {
    }

    public static function key(): string
    {
        return 'dal';
    }

    public function configure(array $options): void
    {
    }

    public function s1Listing(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', $params->categoryId));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->addAssociation('manufacturer');
        $criteria->setLimit($params->limit);
        $criteria->setOffset(($params->page - 1) * $params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $result = $this->salesChannelProductRepository->search($criteria, $this->context($params));

        $items = [];
        foreach ($result->getEntities() as $product) {
            \assert($product instanceof ProductEntity);
            $items[] = [
                'id' => $product->getId(),
                'productNumber' => $product->getProductNumber(),
                'name' => $product->getTranslation('name'),
                'manufacturer' => $product->getManufacturer()?->getTranslation('name'),
                'cover' => $product->getCover()?->getMedia()?->getPath(),
                'price' => $this->price($product, $params),
            ];
        }

        return ['total' => $result->getTotal(), 'items' => $items];
    }

    public function s2Detail(Params $params): array
    {
        $criteria = new Criteria([$params->variantId]);
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('categories');

        $product = $this->salesChannelProductRepository->search($criteria, $this->context($params))->getEntities()->first();
        if (!$product instanceof ProductEntity) {
            throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);
        }

        $prices = [];
        foreach ($product->getPrices() ?? [] as $price) {
            $prices[] = [
                'quantityStart' => $price->getQuantityStart(),
                'quantityEnd' => $price->getQuantityEnd(),
                'price' => ResultContract::price($price->getPrice()->getCurrencyPrice($params->currencyId)?->getGross()),
            ];
        }
        usort($prices, static fn (array $a, array $b): int => $a['quantityStart'] <=> $b['quantityStart']);

        $options = [];
        foreach ($product->getOptions() ?? [] as $option) {
            $options[] = ['group' => (string) $option->getGroup()?->getTranslation('name'), 'name' => (string) $option->getTranslation('name')];
        }
        usort($options, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        $categories = array_values(array_map(ResultContract::hex(...), array_keys($product->getCategories()?->getElements() ?? [])));
        sort($categories);

        return [
            'id' => $product->getId(),
            'productNumber' => $product->getProductNumber(),
            'name' => $product->getTranslation('name'),
            'description' => $product->getTranslation('description'),
            'manufacturerId' => $product->getManufacturerId(),
            'manufacturer' => $product->getManufacturer()?->getTranslation('name'),
            'cover' => $product->getCover()?->getMedia()?->getPath(),
            'price' => $this->price($product, $params),
            'prices' => $prices,
            'options' => $options,
            'categories' => $categories,
        ];
    }

    public function s3Search(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('name', $params->searchTerm));
        $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $params->manufacturerIds));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->addAssociation('manufacturer');
        $criteria->setLimit($params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addAggregation(new MinAggregation('minPrice', 'price.gross'));
        $criteria->addAggregation(new MaxAggregation('maxPrice', 'price.gross'));
        $criteria->addAggregation(new TermsAggregation('manufacturers', 'manufacturerId'));

        $result = $this->salesChannelProductRepository->search($criteria, $this->context($params));

        $items = [];
        foreach ($result->getEntities() as $product) {
            \assert($product instanceof ProductEntity);
            $items[] = [
                'id' => $product->getId(),
                'productNumber' => $product->getProductNumber(),
                'name' => $product->getTranslation('name'),
                'manufacturer' => $product->getManufacturer()?->getTranslation('name'),
                'price' => $this->price($product, $params),
            ];
        }

        $min = $result->getAggregations()->get('minPrice');
        $max = $result->getAggregations()->get('maxPrice');
        $terms = $result->getAggregations()->get('manufacturers');
        $manufacturers = [];
        if ($terms instanceof TermsResult) {
            foreach ($terms->getBuckets() as $bucket) {
                if ($bucket->getKey() !== null) {
                    $manufacturers[ResultContract::hex((string) $bucket->getKey())] = $bucket->getCount();
                }
            }
        }
        ksort($manufacturers);

        return [
            'total' => $result->getTotal(),
            'items' => $items,
            'aggregations' => [
                'minPrice' => $min instanceof MinResult ? ResultContract::price($min->getMin()) : null,
                'maxPrice' => $max instanceof MaxResult ? ResultContract::price($max->getMax()) : null,
                'manufacturers' => $manufacturers,
            ],
        ];
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);

        $payload = [];
        foreach ($set->products as $product) {
            $payload[] = [
                'id' => $product['id'],
                'productNumber' => $product['productNumber'],
                'stock' => $product['stock'],
                'taxId' => $params->taxId,
                'active' => true,
                'price' => [['currencyId' => $params->currencyId, 'gross' => $product['gross'], 'net' => $product['net'], 'linked' => true]],
                'translations' => [
                    $params->systemLanguageId => ['name' => $product['nameEn']],
                    $params->languageId => ['name' => $product['nameDe']],
                ],
                'categories' => array_map(static fn (string $id): array => ['id' => $id], $product['categoryIds']),
                'visibilities' => [['id' => Uuid::randomHex(), 'salesChannelId' => $params->salesChannelId, 'visibility' => 30]],
            ];
        }
        $this->productRepository->create($payload, $context);

        $updates = [];
        foreach ($set->products as $product) {
            $updates[] = ['id' => $product['id'], 'stock' => $product['newStock']];
        }
        $this->productRepository->update($updates, $context);
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $context = $this->context($params);
        $criteria = $this->requestCriteriaBuilder->fromArray($params->criteriaJson, new Criteria(), $this->productDefinition, $context->getContext());

        $result = $this->salesChannelProductRepository->search($criteria, $context);

        $items = [];
        foreach ($result->getEntities() as $product) {
            \assert($product instanceof ProductEntity);
            $items[] = [
                'id' => $product->getId(),
                'productNumber' => $product->getProductNumber(),
                'manufacturer' => $product->getManufacturer()?->getTranslation('name'),
            ];
        }

        return ['total' => $result->getTotal(), 'items' => $items];
    }

    private function price(ProductEntity $product, Params $params): ?string
    {
        return ResultContract::price($product->getPrice()?->getCurrencyPrice($params->currencyId)?->getGross());
    }

    private function context(Params $params): SalesChannelContext
    {
        return $this->context ??= $this->contextFactory->create(Uuid::randomHex(), $params->salesChannelId, [
            SalesChannelContextService::LANGUAGE_ID => $params->languageId,
            SalesChannelContextService::CURRENCY_ID => $params->currencyId,
        ]);
    }
}
