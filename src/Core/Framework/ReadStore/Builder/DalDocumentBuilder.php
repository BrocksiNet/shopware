<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\Aggregate\ProductSearchKeyword\ProductSearchKeywordEntity;
use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Document\ProductDocument;

/**
 * Builds the documents through `product.repository` with `considerInheritance`, one read per shop language (the context gets the language
 * chain a sales channel of that language has); the DAL resolves inheritance and translation fallbacks.
 *
 * The one value the entity does not expose is the `cheapest_price_accessor` column (the entity has the `cheapest_price` container the
 * accessor is computed from, the SQL accessor reads the column), so it is read with one SQL statement per batch.
 *
 * @internal
 */
#[Package('framework')]
final class DalDocumentBuilder implements DocumentBuilder
{
    private const ASSOCIATIONS = [
        'manufacturer', 'tax', 'unit', 'deliveryTime', 'cover.media', 'media.media', 'prices', 'properties.group', 'options.group',
        'visibilities', 'searchKeywords',
    ];

    private ?BuildScope $scope = null;

    private ?PriceCalculator $calculator = null;

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly Connection $connection,
        private readonly DocumentExtenders $extenders,
    ) {
    }

    public static function key(): string
    {
        return 'dal';
    }

    public function reset(): void
    {
        $this->scope = null;
        $this->calculator = null;
    }

    public function build(array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        if ($ids === []) {
            return [];
        }

        $scope = $this->scope ??= BuildScope::load($this->connection);
        $this->calculator ??= new PriceCalculator($scope);

        /** @var array<string, ProductCollection> $reads */
        $reads = [];
        foreach ($scope->languageChains as $languageId => $chain) {
            $criteria = new Criteria($ids);
            $criteria->addAssociations(self::ASSOCIATIONS);
            $context = new Context(new SystemSource(), [], Defaults::CURRENCY, $chain, Defaults::LIVE_VERSION, 1.0, true);
            $reads[$languageId] = $this->productRepository->search($criteria, $context)->getEntities();
        }

        // language independent values come from the system language read, which also holds the untranslated position of options
        $base = $reads[Defaults::LANGUAGE_SYSTEM] ?? reset($reads);
        if (!$base instanceof ProductCollection) {
            return [];
        }

        $accessors = $this->cheapestPriceAccessors($base);

        $documents = [];
        foreach ($ids as $id) {
            $product = $base->get($id);
            if (!$product instanceof ProductEntity) {
                continue;
            }

            $document = new ProductDocument();
            $document->id = $id;
            $document->parentId = $product->getParentId();
            $document->productNumber = $product->getProductNumber();
            $document->displayGroup = $product->getDisplayGroup();
            $listingConfig = $product->getVariantListingConfig();
            $document->variantListingConfig = $listingConfig instanceof VariantListingConfig ? Normalizer::listingConfig([
                'displayParent' => $listingConfig->getDisplayParent(),
                'mainVariantId' => $listingConfig->getMainVariantId(),
                'configuratorGroupConfig' => $listingConfig->getConfiguratorGroupConfig(),
            ]) : null;
            $document->childCount = (int) $product->getChildCount();
            $document->active = self::bool($product->get('active'));
            $document->available = (bool) $product->get('available');
            $document->stock = (int) $product->get('stock');
            $document->availableStock = self::int($product->get('availableStock'));
            $document->isCloseout = self::bool($product->get('isCloseout'));
            $document->minPurchase = self::int($product->get('minPurchase'));
            $document->maxPurchase = self::int($product->get('maxPurchase'));
            $document->purchaseSteps = self::int($product->get('purchaseSteps'));
            $document->purchaseUnit = self::float($product->get('purchaseUnit'));
            $document->referenceUnit = self::float($product->get('referenceUnit'));
            $document->restockTime = self::int($product->get('restockTime'));
            $document->ean = $product->getEan();
            $document->manufacturerNumber = $product->getManufacturerNumber();
            $document->weight = self::float($product->get('weight'));
            $document->ratingAverage = self::float($product->get('ratingAverage'));
            $document->markAsTopseller = self::bool($product->get('markAsTopseller'));
            $document->shippingFree = self::bool($product->get('shippingFree'));
            $document->sales = (int) $product->get('sales');
            $document->releaseDate = self::date($product->getReleaseDate());
            $document->createdAt = self::date($product->getCreatedAt());
            $document->updatedAt = self::date($product->getUpdatedAt());

            foreach ($reads as $languageId => $read) {
                $translated = $read->get($id);
                \assert($translated instanceof ProductEntity);
                foreach (['name', 'description', 'metaTitle', 'metaDescription', 'keywords', 'packUnit', 'packUnitPlural'] as $field) {
                    $document->{$field}[$languageId] = $translated->getTranslation($field);
                }
                $customFields = $translated->getTranslation('customFields');
                $document->customFields[$languageId] = \is_array($customFields) ? $customFields : [];
            }

            $document->manufacturerId = $product->getManufacturerId();
            $manufacturer = $product->getManufacturer();
            if ($manufacturer !== null) {
                $document->manufacturer = [
                    'id' => $manufacturer->getId(),
                    'name' => self::perLanguage($reads, $id, static fn (ProductEntity $p): mixed => $p->getManufacturer()?->getTranslation('name')),
                    'link' => self::perLanguage($reads, $id, static fn (ProductEntity $p): mixed => $p->getManufacturer()?->getTranslation('link')),
                    'mediaId' => $manufacturer->getMediaId(),
                ];
            }
            $document->taxId = $product->getTaxId();
            $tax = $product->getTax();
            $document->tax = $tax === null ? null : ['id' => $tax->getId(), 'taxRate' => $tax->getTaxRate(), 'name' => $tax->getName()];
            $document->unitId = $product->getUnitId();
            $unit = $product->getUnit();
            if ($unit !== null) {
                $document->unit = [
                    'id' => $unit->getId(),
                    'shortCode' => self::perLanguage($reads, $id, static fn (ProductEntity $p): mixed => $p->getUnit()?->getTranslation('shortCode')),
                    'name' => self::perLanguage($reads, $id, static fn (ProductEntity $p): mixed => $p->getUnit()?->getTranslation('name')),
                ];
            }
            $document->deliveryTimeId = $product->getDeliveryTimeId();
            $deliveryTime = $product->getDeliveryTime();
            if ($deliveryTime !== null) {
                $document->deliveryTime = [
                    'id' => $deliveryTime->getId(),
                    'name' => self::perLanguage($reads, $id, static fn (ProductEntity $p): mixed => $p->getDeliveryTime()?->getTranslation('name')),
                    'min' => $deliveryTime->getMin(),
                    'max' => $deliveryTime->getMax(),
                    'unit' => $deliveryTime->getUnit(),
                ];
            }
            $document->coverId = $product->getCoverId();
            $cover = $product->getCover();
            $document->cover = $cover === null ? null : self::media($cover);
            $media = array_values($product->getMedia()?->getElements() ?? []);
            usort($media, static fn (ProductMediaEntity $a, ProductMediaEntity $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
            $document->media = array_map(self::media(...), $media);

            $price = $product->getPrice();
            $document->price = $price === null ? null : Normalizer::priceList(self::rawPrices($price));
            $currencyPrices = $this->calculator->currencyPrices($price === null ? null : self::priceColumn($price), null);
            $document->priceGross = $currencyPrices['gross'];
            $document->priceNet = $currencyPrices['net'];
            $document->cheapestPrice = $this->calculator->cheapestPrice($accessors[$id] ?? null);

            $prices = array_values($product->getPrices()?->getElements() ?? []);
            usort($prices, static fn (ProductPriceEntity $a, ProductPriceEntity $b): int => [$a->getRuleId(), $a->getQuantityStart(), $a->getId()] <=> [$b->getRuleId(), $b->getQuantityStart(), $b->getId()]);
            $document->prices = array_map(static fn (ProductPriceEntity $productPrice): array => [
                'id' => $productPrice->getId(),
                'ruleId' => $productPrice->getRuleId(),
                'quantityStart' => $productPrice->getQuantityStart(),
                'quantityEnd' => $productPrice->getQuantityEnd(),
                'price' => Normalizer::priceList(self::rawPrices($productPrice->getPrice())),
            ], $prices);

            foreach ($product->getVisibilities() ?? [] as $visibility) {
                $document->visibilities[$visibility->getSalesChannelId()] = $visibility->getVisibility();
            }

            $document->categoryIds = array_values($product->getCategoryIds() ?? []);
            $document->categoryTree = array_values($product->getCategoryTree() ?? []);
            $document->streamIds = array_values($product->getStreamIds() ?? []);
            $document->tagIds = array_values($product->getTagIds() ?? []);
            $document->propertyIds = array_values($product->getPropertyIds() ?? []);
            $document->optionIds = array_values($product->getOptionIds() ?? []);
            $document->properties = self::options($reads, $id, static fn (ProductEntity $p): ?PropertyGroupOptionCollection => $p->getProperties());
            $document->options = self::options($reads, $id, static fn (ProductEntity $p): ?PropertyGroupOptionCollection => $p->getOptions());
            $document->states = array_values($product->getStates());

            foreach ($scope->languageIds() as $languageId) {
                $document->searchKeywords[$languageId] = [];
            }
            $keywords = array_values($product->getSearchKeywords()?->getElements() ?? []);
            usort($keywords, static fn (ProductSearchKeywordEntity $a, ProductSearchKeywordEntity $b): int => $a->getKeyword() <=> $b->getKeyword());
            foreach ($keywords as $keyword) {
                $document->searchKeywords[$keyword->getLanguageId()][$keyword->getKeyword()] = $keyword->getRanking();
            }

            $documents[$id] = $document->toArray();
        }

        return $this->extenders->apply($documents);
    }

    /**
     * Own accessor, the parent's when the own is `NULL` (the field is inherited).
     *
     * @return array<string, array<mixed>|null>
     */
    private function cheapestPriceAccessors(ProductCollection $products): array
    {
        $ids = array_values(array_unique(array_filter([...array_values($products->getIds()), ...array_values($products->map(static fn (ProductEntity $p): ?string => $p->getParentId()))])));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string|null> $raw */
        $raw = $this->connection->fetchAllKeyValue(
            \sprintf('SELECT %s, cheapest_price_accessor FROM product WHERE id IN (:ids) AND version_id = :version', IdBinding::text('id')),
            ['ids' => IdBinding::values($ids), 'version' => IdBinding::value(Defaults::LIVE_VERSION)],
            ['ids' => IdBinding::listType(), 'version' => IdBinding::type()]
        );

        $result = [];
        foreach ($products as $product) {
            $json = $raw[$product->getId()] ?? ($product->getParentId() !== null ? ($raw[$product->getParentId()] ?? null) : null);
            $decoded = $json === null ? null : json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            $result[$product->getId()] = \is_array($decoded) ? $decoded : null;
        }

        return $result;
    }

    /**
     * @param array<string, ProductCollection> $reads
     * @param \Closure(ProductEntity): mixed $value
     *
     * @return array<string, mixed>
     */
    private static function perLanguage(array $reads, string $id, \Closure $value): array
    {
        $result = [];
        foreach ($reads as $languageId => $read) {
            $product = $read->get($id);
            $result[$languageId] = $product instanceof ProductEntity ? $value($product) : null;
        }

        return $result;
    }

    /**
     * @param array<string, ProductCollection> $reads
     * @param \Closure(ProductEntity): ?PropertyGroupOptionCollection $collection
     *
     * @return list<array<string, mixed>>
     */
    private static function options(array $reads, string $id, \Closure $collection): array
    {
        $base = $reads[Defaults::LANGUAGE_SYSTEM] ?? reset($reads);
        $product = $base instanceof ProductCollection ? $base->get($id) : null;
        if (!$product instanceof ProductEntity) {
            return [];
        }

        $names = [];
        $groupNames = [];
        foreach ($reads as $languageId => $read) {
            $translated = $read->get($id);
            if (!$translated instanceof ProductEntity) {
                continue;
            }
            foreach ($collection($translated) ?? [] as $option) {
                \assert($option instanceof PropertyGroupOptionEntity);
                $names[$option->getId()][$languageId] = $option->getTranslation('name');
                $groupNames[$option->getId()][$languageId] = $option->getGroup()?->getTranslation('name');
            }
        }

        $list = [];
        foreach ($collection($product) ?? [] as $option) {
            \assert($option instanceof PropertyGroupOptionEntity);
            $group = $option->getGroup();
            $list[] = [
                'id' => $option->getId(),
                'groupId' => $option->getGroupId(),
                'name' => $names[$option->getId()] ?? [],
                'position' => self::int($option->getTranslation('position')),
                'colorHexCode' => $option->getColorHexCode(),
                'mediaId' => $option->getMediaId(),
                'group' => $group === null ? null : [
                    'id' => $group->getId(),
                    'name' => $groupNames[$option->getId()] ?? [],
                    'displayType' => $group->getDisplayType(),
                    'sortingType' => $group->getSortingType(),
                    'filterable' => $group->getFilterable(),
                    'visibleOnProductDetailPage' => $group->getVisibleOnProductDetailPage(),
                    'position' => self::int($group->getTranslation('position')),
                ],
            ];
        }
        usort($list, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $list;
    }

    /**
     * @return array{id: string, mediaId: ?string, position: int, path: ?string}
     */
    private static function media(ProductMediaEntity $media): array
    {
        return ['id' => $media->getId(), 'mediaId' => $media->getMediaId(), 'position' => $media->getPosition(), 'path' => $media->getMedia()?->getPath()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rawPrices(PriceCollection $prices): array
    {
        $list = [];
        foreach ($prices as $price) {
            $list[] = self::rawPrice($price);
        }

        return $list;
    }

    /**
     * The layout of the `product.price` column, `{c<currencyId>: {gross, net}}`, for the price calculator.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function priceColumn(PriceCollection $prices): array
    {
        $column = [];
        foreach ($prices as $price) {
            $column['c' . $price->getCurrencyId()] = self::rawPrice($price);
        }

        return $column;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawPrice(Price $price): array
    {
        $raw = ['currencyId' => $price->getCurrencyId(), 'gross' => $price->getGross(), 'net' => $price->getNet(), 'linked' => $price->getLinked()];
        foreach (['listPrice' => $price->getListPrice(), 'regulationPrice' => $price->getRegulationPrice()] as $key => $nested) {
            if ($nested !== null) {
                $raw[$key] = ['gross' => $nested->getGross(), 'net' => $nested->getNet(), 'linked' => $nested->getLinked()];
            }
        }
        if ($price->getPercentage() !== null) {
            $raw['percentage'] = $price->getPercentage();
        }

        return $raw;
    }

    private static function date(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private static function bool(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
