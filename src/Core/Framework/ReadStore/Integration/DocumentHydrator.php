<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection as CalculatedPriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CalculatedCheapestPrice;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPrice;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceContainer;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult as DalTermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult as DalAvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult as DalCountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult as DalEntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult as DalMaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult as DalMinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult as DalStatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult as DalSumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Bridge\BridgedCriteria;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult as PortAggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult as PortStatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult as PortTermsResult;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\System\Unit\UnitEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Hydrates read store product documents into either:
 * - Shape (a): {@see SalesChannelProductEntity} instances with `product.loaded` and `sales_channel.product.loaded`
 *   dispatched (so `ProductSubscriber::salesChannelLoaded()` and plugin subscribers run as normal);
 * - Shape (b): lightweight {@see ArrayEntity} DTOs directly from the document without entity graph allocation
 *   or `ProductSubscriber` execution (doc 33 projection shape).
 *
 * @internal
 */
#[Package('framework')]
final class DocumentHydrator
{
    public function __construct(
        private readonly SalesChannelProductDefinition $salesChannelProductDefinition,
        private readonly ProductDefinition $productDefinition,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
    ) {
    }

    /**
     * Shape (a) without loaded events, for a caller that dispatches them itself.
     * Wave 10 O1a: the `SourceDocumentEntityReader` in `src/Elasticsearch` returns these entities to
     * `SalesChannelRepository::read()`, which then dispatches the sales channel loaded events (live prices, purchase limits).
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<SalesChannelProductEntity>
     */
    public function hydrateEntitiesWithoutEvents(array $documents, string $languageId, ?Criteria $criteria = null): array
    {
        $entities = [];
        foreach ($documents as $doc) {
            $entities[] = $this->hydrateEntity($doc, $languageId, true, true, $criteria);
        }

        return $entities;
    }

    /**
     * Shape (a): full {@see SalesChannelProductEntity} hydration + loaded events.
     *
     * @param list<array<string, mixed>> $documents
     */
    public function hydrateEntities(
        array $documents,
        SalesChannelContext $context,
        bool $livePrices = true,
        bool $liveStock = true,
        ?Criteria $criteria = null,
    ): ProductCollection {
        $collection = new ProductCollection();
        if ($documents === []) {
            return $collection;
        }

        $languageId = $context->getLanguageId();
        $entities = [];
        foreach ($documents as $doc) {
            $entity = $this->hydrateEntity($doc, $languageId, $livePrices, $liveStock, $criteria);
            $entities[] = $entity;
            $collection->add($entity);
        }

        if ($livePrices && $liveStock) {
            $this->dispatcher->dispatch(
                new EntityLoadedEvent($this->productDefinition, $entities, $context->getContext()),
                ProductEvents::PRODUCT_LOADED_EVENT,
            );
            $this->dispatcher->dispatch(
                new SalesChannelEntityLoadedEvent($this->salesChannelProductDefinition, $entities, $context),
                'sales_channel.' . ProductEvents::PRODUCT_LOADED_EVENT,
            );
        } elseif ($livePrices) {
            // Live prices ON, live stock/maxPurchase OFF: run loaded events, then overwrite maxPurchase with document value
            $this->dispatcher->dispatch(
                new SalesChannelEntityLoadedEvent($this->salesChannelProductDefinition, $entities, $context),
                'sales_channel.' . ProductEvents::PRODUCT_LOADED_EVENT,
            );
            foreach ($entities as $index => $entity) {
                $doc = $documents[$index];
                $entity->setCalculatedMaxPurchase((int) ($doc['maxPurchase'] ?? $doc['availableStock'] ?? $doc['stock'] ?? 0));
            }
        } else {
            // Live prices OFF: populate calculated prices directly from the pre-stored document currency price
            foreach ($entities as $index => $entity) {
                $this->applyDocumentPrice($entity, $documents[$index], $context);
                if (!$liveStock) {
                    $entity->setCalculatedMaxPurchase((int) ($documents[$index]['maxPurchase'] ?? $documents[$index]['availableStock'] ?? $documents[$index]['stock'] ?? 0));
                }
            }
        }

        return $collection;
    }

    /**
     * Shape (b): lightweight DTO projection without entity graphs or `ProductSubscriber`.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return EntityCollection<ArrayEntity>
     */
    public function hydrateDtos(array $documents, SalesChannelContext $context): EntityCollection
    {
        $languageId = $context->getLanguageId();
        $currencyId = $context->getCurrencyId();
        $gross = $context->getTaxState() !== 'net';
        $priceKey = $gross ? 'priceGross' : 'priceNet';

        $collection = new EntityCollection();
        foreach ($documents as $doc) {
            $id = (string) $doc['id'];
            $dto = new ArrayEntity([
                'id' => $id,
                'parentId' => $doc['parentId'] ?? null,
                'productNumber' => $doc['productNumber'] ?? '',
                'name' => $this->lang($doc['name'] ?? null, $languageId),
                'description' => $this->lang($doc['description'] ?? null, $languageId),
                'active' => $doc['active'] ?? null,
                'available' => $doc['available'] ?? null,
                'stock' => $doc['stock'] ?? 0,
                'availableStock' => $doc['availableStock'] ?? null,
                'isCloseout' => $doc['isCloseout'] ?? null,
                'minPurchase' => $doc['minPurchase'] ?? null,
                'maxPurchase' => $doc['maxPurchase'] ?? null,
                'purchaseSteps' => $doc['purchaseSteps'] ?? null,
                'ratingAverage' => $doc['ratingAverage'] ?? null,
                'shippingFree' => $doc['shippingFree'] ?? null,
                'markAsTopseller' => $doc['markAsTopseller'] ?? null,
                'childCount' => $doc['childCount'] ?? 0,
                'displayGroup' => $doc['displayGroup'] ?? null,
                'releaseDate' => $doc['releaseDate'] ?? null,
                'manufacturerId' => $doc['manufacturerId'] ?? null,
                'manufacturerName' => \is_array($doc['manufacturer'] ?? null) ? $this->lang($doc['manufacturer']['name'] ?? null, $languageId) : null,
                'coverId' => $doc['coverId'] ?? null,
                'coverPath' => \is_array($doc['cover'] ?? null) ? ($doc['cover']['path'] ?? null) : null,
                'price' => $doc[$priceKey][$currencyId] ?? $doc[$priceKey][Defaults::CURRENCY] ?? null,
                'cheapestPrice' => $this->resolveCheapestDtoPrice($doc, $context),
                'categoryTree' => $doc['categoryTree'] ?? [],
                'propertyIds' => $doc['propertyIds'] ?? [],
                'optionIds' => $doc['optionIds'] ?? [],
                'customFields' => $this->langArray($doc['customFields'] ?? null, $languageId),
            ]);
            $dto->setUniqueIdentifier($id);
            $collection->add($dto);
        }

        return $collection;
    }

    /**
     * @param array<string, PortAggregationResult> $portResults
     */
    public function hydrateAggregations(
        array $portResults,
        BridgedCriteria $bridged,
        SalesChannelContext $context,
    ): AggregationResultCollection {
        $collection = new AggregationResultCollection();

        $byName = [];
        foreach ($bridged->criteria->aggregations as $aggregation) {
            $byName[$aggregation->name] = $aggregation;
        }

        foreach ($portResults as $name => $result) {
            if ($result instanceof PortStatsResult) {
                $collection->add(new DalStatsResult(
                    $name,
                    $result->min,
                    $result->max,
                    $result->avg,
                    $result->sum,
                ));

                continue;
            }

            if ($result instanceof MetricResult) {
                $definition = $byName[$name] ?? null;
                $dalResult = match (true) {
                    $definition instanceof Min => new DalMinResult($name, $result->value),
                    $definition instanceof Max => new DalMaxResult($name, $result->value),
                    $definition instanceof Avg => new DalAvgResult($name, (float) ($result->value ?? 0.0)),
                    $definition instanceof Sum => new DalSumResult($name, (float) ($result->value ?? 0.0)),
                    $definition instanceof Count => new DalCountResult($name, (int) ($result->value ?? 0)),
                    default => new DalMinResult($name, $result->value),
                };
                $collection->add($dalResult);

                continue;
            }

            if ($result instanceof PortTermsResult) {
                if (isset($bridged->entityAggregations[$name])) {
                    $entityName = $bridged->entityAggregations[$name];
                    $keys = [];
                    foreach ($result->buckets as $bucket) {
                        if (\is_string($bucket['key']) && $bucket['key'] !== '') {
                            $keys[] = $bucket['key'];
                        }
                    }

                    if ($keys === []) {
                        $collection->add(new DalEntityResult($name, new EntityCollection()));
                    } else {
                        $repository = $this->definitionRegistry->getRepository($entityName);
                        $entities = $repository->search(new Criteria($keys), $context->getContext())->getEntities();
                        $collection->add(new DalEntityResult($name, $entities));
                    }

                    continue;
                }

                $buckets = [];
                foreach ($result->buckets as $bucket) {
                    $buckets[] = new Bucket(
                        $bucket['key'] === null ? null : (string) $bucket['key'],
                        $bucket['count'],
                        null,
                    );
                }
                $collection->add(new DalTermsResult($name, $buckets));
            }
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function hydrateEntity(
        array $doc,
        string $languageId,
        bool $livePrices,
        bool $liveStock,
        ?Criteria $criteria = null,
    ): SalesChannelProductEntity {
        $entity = new SalesChannelProductEntity();
        $entity->setUniqueIdentifier((string) $doc['id']);

        $name = $this->lang($doc['name'] ?? null, $languageId);
        $description = $this->lang($doc['description'] ?? null, $languageId);
        $metaTitle = $this->lang($doc['metaTitle'] ?? null, $languageId);
        $metaDescription = $this->lang($doc['metaDescription'] ?? null, $languageId);
        $keywords = $this->lang($doc['keywords'] ?? null, $languageId);
        $packUnit = $this->lang($doc['packUnit'] ?? null, $languageId);
        $packUnitPlural = $this->lang($doc['packUnitPlural'] ?? null, $languageId);
        $customFields = $this->langArray($doc['customFields'] ?? null, $languageId);

        $data = [
            'id' => (string) $doc['id'],
            'versionId' => Defaults::LIVE_VERSION,
            'parentId' => $doc['parentId'] ?? null,
            'productNumber' => (string) ($doc['productNumber'] ?? ''),
            'displayGroup' => $doc['displayGroup'] ?? null,
            'variantListingConfig' => $doc['variantListingConfig'] ?? null,
            'childCount' => (int) ($doc['childCount'] ?? 0),
            'active' => isset($doc['active']) ? (bool) $doc['active'] : null,
            'available' => isset($doc['available']) ? (bool) $doc['available'] : null,
            'stock' => (int) ($doc['stock'] ?? 0),
            'availableStock' => isset($doc['availableStock']) ? (int) $doc['availableStock'] : null,
            'isCloseout' => isset($doc['isCloseout']) ? (bool) $doc['isCloseout'] : null,
            'minPurchase' => isset($doc['minPurchase']) ? (int) $doc['minPurchase'] : null,
            'maxPurchase' => isset($doc['maxPurchase']) ? (int) $doc['maxPurchase'] : null,
            'purchaseSteps' => isset($doc['purchaseSteps']) ? (int) $doc['purchaseSteps'] : null,
            'purchaseUnit' => isset($doc['purchaseUnit']) ? (float) $doc['purchaseUnit'] : null,
            'referenceUnit' => isset($doc['referenceUnit']) ? (float) $doc['referenceUnit'] : null,
            'restockTime' => isset($doc['restockTime']) ? (int) $doc['restockTime'] : null,
            'ean' => $doc['ean'] ?? null,
            'manufacturerNumber' => $doc['manufacturerNumber'] ?? null,
            'weight' => isset($doc['weight']) ? (float) $doc['weight'] : null,
            'ratingAverage' => isset($doc['ratingAverage']) ? (float) $doc['ratingAverage'] : null,
            'markAsTopseller' => isset($doc['markAsTopseller']) ? (bool) $doc['markAsTopseller'] : null,
            'shippingFree' => isset($doc['shippingFree']) ? (bool) $doc['shippingFree'] : null,
            'sales' => (int) ($doc['sales'] ?? 0),
            'releaseDate' => $this->date($doc['releaseDate'] ?? null),
            'createdAt' => $this->date($doc['createdAt'] ?? null),
            'updatedAt' => $this->date($doc['updatedAt'] ?? null),
            'name' => $name,
            'description' => $description,
            'metaTitle' => $metaTitle,
            'metaDescription' => $metaDescription,
            'keywords' => $keywords,
            'packUnit' => $packUnit,
            'packUnitPlural' => $packUnitPlural,
            'customFields' => $customFields,
            'translated' => [
                'name' => $name,
                'description' => $description,
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'keywords' => $keywords,
                'packUnit' => $packUnit,
                'packUnitPlural' => $packUnitPlural,
                'customFields' => $customFields ?? [],
            ],
            'manufacturerId' => $doc['manufacturerId'] ?? null,
            'taxId' => $doc['taxId'] ?? null,
            'unitId' => $doc['unitId'] ?? null,
            'deliveryTimeId' => $doc['deliveryTimeId'] ?? null,
            'coverId' => $doc['coverId'] ?? null,
            'categoryTree' => $doc['categoryTree'] ?? [],
            'categoryIds' => $doc['categoryIds'] ?? [],
            'streamIds' => $doc['streamIds'] ?? [],
            'tagIds' => $doc['tagIds'] ?? [],
            'propertyIds' => $doc['propertyIds'] ?? [],
            'optionIds' => $doc['optionIds'] ?? [],
            'states' => $doc['states'] ?? [],
        ];

        if (\is_array($doc['price'] ?? null)) {
            $data['price'] = $this->hydratePriceCollection($doc['price']);
        }

        $data['prices'] = $this->hydrateAdvancedPrices($doc['prices'] ?? []);
        $data['cheapestPrice'] = $this->hydrateCheapestPriceContainer($doc);

        if (\is_array($doc['manufacturer'] ?? null) && ($criteria === null || $criteria->hasAssociation('manufacturer'))) {
            $mfr = new ProductManufacturerEntity();
            $mfr->setUniqueIdentifier((string) $doc['manufacturer']['id']);
            $mfrName = $this->lang($doc['manufacturer']['name'] ?? null, $languageId);
            $mfr->assign([
                'id' => (string) $doc['manufacturer']['id'],
                'name' => $mfrName,
                'link' => $doc['manufacturer']['link'] ?? null,
                'mediaId' => $doc['manufacturer']['mediaId'] ?? null,
                'translated' => ['name' => $mfrName],
            ]);
            $data['manufacturer'] = $mfr;
        }

        if (\is_array($doc['tax'] ?? null)) {
            $tax = new TaxEntity();
            $tax->setUniqueIdentifier((string) $doc['tax']['id']);
            $tax->assign([
                'id' => (string) $doc['tax']['id'],
                'taxRate' => (float) ($doc['tax']['taxRate'] ?? 0.0),
                'name' => (string) ($doc['tax']['name'] ?? ''),
            ]);
            $data['tax'] = $tax;
        }

        if (\is_array($doc['unit'] ?? null)) {
            $unit = new UnitEntity();
            $unit->setUniqueIdentifier((string) $doc['unit']['id']);
            $uName = $this->lang($doc['unit']['name'] ?? null, $languageId);
            $uShort = $this->lang($doc['unit']['shortCode'] ?? null, $languageId);
            $unit->assign([
                'id' => (string) $doc['unit']['id'],
                'name' => $uName,
                'shortCode' => $uShort,
                'translated' => ['name' => $uName, 'shortCode' => $uShort],
            ]);
            $data['unit'] = $unit;
        }

        if (\is_array($doc['deliveryTime'] ?? null)) {
            $dt = new DeliveryTimeEntity();
            $dt->setUniqueIdentifier((string) $doc['deliveryTime']['id']);
            $dtName = $this->lang($doc['deliveryTime']['name'] ?? null, $languageId);
            $dt->assign([
                'id' => (string) $doc['deliveryTime']['id'],
                'name' => $dtName,
                'min' => (int) ($doc['deliveryTime']['min'] ?? 0),
                'max' => (int) ($doc['deliveryTime']['max'] ?? 0),
                'unit' => (string) ($doc['deliveryTime']['unit'] ?? ''),
                'translated' => ['name' => $dtName],
            ]);
            $data['deliveryTime'] = $dt;
        }

        if (\is_array($doc['cover'] ?? null)) {
            $data['cover'] = $this->hydrateProductMedia($doc['cover']);
        }

        $mediaCollection = new ProductMediaCollection();
        foreach ($doc['media'] ?? [] as $item) {
            if (\is_array($item)) {
                $mediaCollection->add($this->hydrateProductMedia($item));
            }
        }
        $data['media'] = $mediaCollection;

        $data['properties'] = $this->hydrateOptionCollection($doc['properties'] ?? [], $languageId);
        $data['options'] = $this->hydrateOptionCollection($doc['options'] ?? [], $languageId);

        $entity->assign($data);

        if (!$livePrices && !$liveStock) {
            $entity->setCalculatedPrices(new CalculatedPriceCollection());
        }

        return $entity;
    }

    /**
     * @param array<mixed> $rawPrices
     */
    private function hydratePriceCollection(array $rawPrices): PriceCollection
    {
        $collection = new PriceCollection();
        foreach ($rawPrices as $raw) {
            if (!\is_array($raw) || !isset($raw['currencyId'])) {
                continue;
            }
            $listPrice = null;
            if (\is_array($raw['listPrice'] ?? null)) {
                $listPrice = new Price(
                    (string) $raw['currencyId'],
                    (float) ($raw['listPrice']['net'] ?? 0.0),
                    (float) ($raw['listPrice']['gross'] ?? 0.0),
                    (bool) ($raw['listPrice']['linked'] ?? true),
                );
            }
            $collection->add(new Price(
                (string) $raw['currencyId'],
                (float) ($raw['net'] ?? 0.0),
                (float) ($raw['gross'] ?? 0.0),
                (bool) ($raw['linked'] ?? true),
                $listPrice,
            ));
        }

        return $collection;
    }

    /**
     * @param list<array<string, mixed>> $rawRules
     */
    private function hydrateAdvancedPrices(array $rawRules): ProductPriceCollection
    {
        $collection = new ProductPriceCollection();
        foreach ($rawRules as $raw) {
            if (!\is_array($raw) || !isset($raw['id'])) {
                continue;
            }
            $rule = new ProductPriceEntity();
            $rule->setUniqueIdentifier((string) $raw['id']);
            $rule->assign([
                'id' => (string) $raw['id'],
                'ruleId' => (string) ($raw['ruleId'] ?? ''),
                'quantityStart' => (int) ($raw['quantityStart'] ?? 1),
                'quantityEnd' => isset($raw['quantityEnd']) ? (int) $raw['quantityEnd'] : null,
                'price' => \is_array($raw['price'] ?? null) ? $this->hydratePriceCollection($raw['price']) : new PriceCollection(),
            ]);
            $collection->add($rule);
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function hydrateCheapestPriceContainer(array $doc): CheapestPriceContainer
    {
        $id = (string) $doc['id'];
        $rawPrice = \is_array($doc['price'] ?? null) ? $doc['price'] : [];
        $priceByCurrency = [];
        foreach ($rawPrice as $row) {
            if (\is_array($row) && isset($row['currencyId'])) {
                $priceByCurrency[(string) $row['currencyId']] = $row;
            }
        }

        $parentId = \is_string($doc['parentId'] ?? null) ? $doc['parentId'] : $id;
        $value = [
            $id => [
                'default' => [
                    'parent_id' => $parentId,
                    'variant_id' => $id,
                    'rule_id' => null,
                    'is_ranged' => '0',
                    'price' => $priceByCurrency,
                    'min_purchase' => $doc['minPurchase'] ?? 1,
                    'unit_id' => $doc['unitId'] ?? null,
                    'purchase_unit' => $doc['purchaseUnit'] ?? null,
                    'reference_unit' => $doc['referenceUnit'] ?? null,
                    'child_count' => $doc['childCount'] ?? 0,
                ],
            ],
        ];

        foreach ($doc['prices'] ?? [] as $rule) {
            if (!\is_array($rule) || !isset($rule['ruleId'])) {
                continue;
            }
            $ruleId = (string) $rule['ruleId'];
            if (isset($value[$id][$ruleId])) {
                $value[$id][$ruleId]['is_ranged'] = '1';

                continue;
            }
            $ruleCurrency = [];
            foreach ($rule['price'] ?? [] as $row) {
                if (\is_array($row) && isset($row['currencyId'])) {
                    $ruleCurrency[(string) $row['currencyId']] = $row;
                }
            }
            $value[$id][$ruleId] = [
                'parent_id' => $parentId,
                'variant_id' => $id,
                'rule_id' => $ruleId,
                'is_ranged' => ($rule['quantityEnd'] ?? null) !== null ? '1' : '0',
                'price' => $ruleCurrency,
                'min_purchase' => $doc['minPurchase'] ?? 1,
                'unit_id' => $doc['unitId'] ?? null,
                'purchase_unit' => $doc['purchaseUnit'] ?? null,
                'reference_unit' => $doc['referenceUnit'] ?? null,
                'child_count' => $doc['childCount'] ?? 0,
            ];
        }

        return new CheapestPriceContainer($value);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hydrateProductMedia(array $raw): ProductMediaEntity
    {
        $pm = new ProductMediaEntity();
        $pm->setUniqueIdentifier((string) $raw['id']);

        $media = new MediaEntity();
        $mediaId = (string) ($raw['mediaId'] ?? $raw['id']);
        $media->setUniqueIdentifier($mediaId);
        $media->assign([
            'id' => $mediaId,
            'path' => $raw['path'] ?? null,
        ]);

        $pm->assign([
            'id' => (string) $raw['id'],
            'mediaId' => $mediaId,
            'position' => (int) ($raw['position'] ?? 0),
            'media' => $media,
        ]);

        return $pm;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function hydrateOptionCollection(array $items, string $languageId): PropertyGroupOptionCollection
    {
        $collection = new PropertyGroupOptionCollection();
        foreach ($items as $item) {
            if (!\is_array($item) || !isset($item['id'])) {
                continue;
            }
            $opt = new PropertyGroupOptionEntity();
            $opt->setUniqueIdentifier((string) $item['id']);
            $optName = $this->lang($item['name'] ?? null, $languageId);
            $assign = [
                'id' => (string) $item['id'],
                'groupId' => (string) ($item['groupId'] ?? ''),
                'name' => $optName,
                'position' => (int) ($item['position'] ?? 0),
                'colorHexCode' => $item['colorHexCode'] ?? null,
                'mediaId' => $item['mediaId'] ?? null,
                'translated' => ['name' => $optName],
            ];
            if (\is_array($item['group'] ?? null)) {
                $group = new PropertyGroupEntity();
                $group->setUniqueIdentifier((string) $item['group']['id']);
                $gName = $this->lang($item['group']['name'] ?? null, $languageId);
                $group->assign([
                    'id' => (string) $item['group']['id'],
                    'name' => $gName,
                    'displayType' => (string) ($item['group']['displayType'] ?? 'text'),
                    'sortingType' => (string) ($item['group']['sortingType'] ?? 'alphanumeric'),
                    'filterable' => (bool) ($item['group']['filterable'] ?? true),
                    'visibleOnProductDetailPage' => (bool) ($item['group']['visibleOnProductDetailPage'] ?? true),
                    'position' => (int) ($item['group']['position'] ?? 0),
                    'translated' => ['name' => $gName],
                ]);
                $assign['group'] = $group;
            }
            $opt->assign($assign);
            $collection->add($opt);
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function applyDocumentPrice(SalesChannelProductEntity $entity, array $doc, SalesChannelContext $context): void
    {
        $currencyId = $context->getCurrencyId();
        $gross = $context->getTaxState() !== 'net';
        $key = $gross ? 'priceGross' : 'priceNet';
        $amount = (float) ($doc[$key][$currencyId] ?? $doc[$key][Defaults::CURRENCY] ?? 0.0);

        $calculated = new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection());
        $entity->setCalculatedPrice($calculated);
        $entity->setCalculatedPrices(new CalculatedPriceCollection());
        $cheapest = new CalculatedCheapestPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection());
        $entity->setCalculatedCheapestPrice($cheapest);

        $container = $entity->get('cheapestPrice');
        if ($container instanceof CheapestPriceContainer) {
            $resolved = $container->resolve($context->getContext());
            $entity->assign([
                'cheapestPrice' => $resolved instanceof CheapestPrice ? $resolved : null,
                'cheapestPriceContainer' => $container,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function resolveCheapestDtoPrice(array $doc, SalesChannelContext $context): ?float
    {
        $currencyId = $context->getCurrencyId();
        $field = $context->getTaxState() !== 'net' ? 'gross' : 'net';
        $cheapest = $doc['cheapestPrice'] ?? [];
        if (!\is_array($cheapest)) {
            return null;
        }
        foreach ($context->getRuleIds() as $ruleId) {
            if (isset($cheapest[$ruleId][$currencyId][$field])) {
                return (float) $cheapest[$ruleId][$currencyId][$field];
            }
        }
        if (isset($cheapest['default'][$currencyId][$field])) {
            return (float) $cheapest['default'][$currencyId][$field];
        }

        return null;
    }

    private function lang(mixed $map, string $languageId): ?string
    {
        if (!\is_array($map)) {
            return null;
        }
        $value = $map[$languageId] ?? $map[Defaults::LANGUAGE_SYSTEM] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function langArray(mixed $map, string $languageId): ?array
    {
        if (!\is_array($map)) {
            return null;
        }
        $value = $map[$languageId] ?? $map[Defaults::LANGUAGE_SYSTEM] ?? null;

        return \is_array($value) ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
