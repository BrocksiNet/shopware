<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Content\Product\ProductDefinition;
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
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The read scenarios on top of {@see Projector}: typed DTOs instead of entities.
 *
 * @internal
 */
#[Package('framework')]
class ProjectionScenarios
{
    private ?SalesChannelContext $context = null;

    public function __construct(
        private readonly Projector $projector,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly RequestCriteriaBuilder $requestCriteriaBuilder,
        private readonly ProductDefinition $productDefinition,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function s1Listing(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', $params->categoryId));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit($params->limit);
        $criteria->setOffset(($params->page - 1) * $params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $result = $this->projector->project(ProductListItem::class, $criteria, $this->context($params));

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = [
                'id' => $item->id,
                'productNumber' => $item->productNumber,
                'name' => $item->name,
                'manufacturer' => $item->manufacturer,
                'cover' => $item->cover,
                'price' => $this->price($item->price, $params),
            ];
        }

        return ['total' => $result['total'], 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function s2Detail(Params $params): array
    {
        $criteria = new Criteria([$params->variantId]);
        $result = $this->projector->project(ProductDetail::class, $criteria, $this->context($params));
        $product = $result['items'][0] ?? throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);

        $prices = [];
        foreach ($product->prices as $price) {
            $prices[] = [
                'quantityStart' => $price->get('quantityStart'),
                'quantityEnd' => $price->get('quantityEnd'),
                'price' => $this->price($price->get('price'), $params),
            ];
        }
        usort($prices, static fn (array $a, array $b): int => $a['quantityStart'] <=> $b['quantityStart']);

        $options = [];
        foreach ($product->options as $option) {
            $options[] = ['group' => (string) Projector::get($option, 'group.name'), 'name' => (string) Projector::get($option, 'name')];
        }
        usort($options, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        $categories = [];
        foreach ($product->categories as $category) {
            $categories[] = ResultContract::hex((string) $category->get('id'));
        }
        sort($categories);

        return [
            'id' => $product->id,
            'productNumber' => $product->productNumber,
            'name' => $product->name,
            'description' => $product->description,
            'manufacturerId' => $product->manufacturerId,
            'manufacturer' => $product->manufacturer,
            'cover' => $product->cover,
            'price' => $this->price($product->price, $params),
            'prices' => $prices,
            'options' => $options,
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function s3Search(Params $params): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('name', $params->searchTerm));
        $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $params->manufacturerIds));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING), new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit($params->limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addAggregation(new MinAggregation('minPrice', 'price.gross'));
        $criteria->addAggregation(new MaxAggregation('maxPrice', 'price.gross'));
        $criteria->addAggregation(new TermsAggregation('manufacturers', 'manufacturerId'));

        $result = $this->projector->project(ProductSearchItem::class, $criteria, $this->context($params));

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = [
                'id' => $item->id,
                'productNumber' => $item->productNumber,
                'name' => $item->name,
                'manufacturer' => $item->manufacturer,
                'price' => $this->price($item->price, $params),
            ];
        }

        $min = $result['aggregations']->get('minPrice');
        $max = $result['aggregations']->get('maxPrice');
        $terms = $result['aggregations']->get('manufacturers');
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
            'total' => $result['total'],
            'items' => $items,
            'aggregations' => [
                'minPrice' => $min instanceof MinResult ? ResultContract::price($min->getMin()) : null,
                'maxPrice' => $max instanceof MaxResult ? ResultContract::price($max->getMax()) : null,
                'manufacturers' => $manufacturers,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function s5CriteriaFromJson(Params $params): array
    {
        $context = $this->context($params);
        $criteria = $this->requestCriteriaBuilder->fromArray($params->criteriaJson, new Criteria(), $this->productDefinition, $context->getContext());

        $result = $this->projector->project(ProductReference::class, $criteria, $context);

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = ['id' => $item->id, 'productNumber' => $item->productNumber, 'manufacturer' => $item->manufacturer];
        }

        return ['total' => $result['total'], 'items' => $items];
    }

    private function price(mixed $price, Params $params): ?string
    {
        $currency = $price?->getCurrencyPrice($params->currencyId);

        return ResultContract::price($currency?->getGross());
    }

    private function context(Params $params): SalesChannelContext
    {
        return $this->context ??= $this->contextFactory->create(Uuid::randomHex(), $params->salesChannelId, [
            SalesChannelContextService::LANGUAGE_ID => $params->languageId,
            SalesChannelContextService::CURRENCY_ID => $params->currencyId,
        ]);
    }
}
